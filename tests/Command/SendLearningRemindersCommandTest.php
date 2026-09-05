<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SendLearningRemindersCommand;
use App\Entity\LearningReminder;
use App\Entity\User;
use App\Enum\LearningReminderFrequency;
use App\Enum\LearningReminderProcessingOutcome;
use App\Repository\LearningReminderRepository;
use App\Services\LearningReminderDispatchLock;
use App\Services\LearningReminderProcessor;
use Doctrine\ORM\EntityManagerInterface;
use libphonenumber\PhoneNumber;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SendLearningRemindersCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private LearningReminderRepository $repository;

    /** @var list<User> */
    private array $users = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(LearningReminderRepository::class);
    }

    protected function tearDown(): void
    {
        $userIds = array_values(array_filter(array_map(
            static fn (User $user): ?int => $user->getId(),
            $this->users,
        )));

        if ([] !== $userIds && $this->entityManager->isOpen()) {
            $this->entityManager->clear();
            $this->entityManager->createQuery(
                'DELETE FROM App\Entity\LearningReminder reminder WHERE IDENTITY(reminder.user) IN (:userIds)'
            )
                ->setParameter('userIds', $userIds)
                ->execute();
            $this->entityManager->createQuery('DELETE FROM App\Entity\User user WHERE user.id IN (:userIds)')
                ->setParameter('userIds', $userIds)
                ->execute();
        }

        $this->users = [];
        parent::tearDown();
    }

    public function testNoReminderReturnsSuccessAndReleasesLock(): void
    {
        $processor = $this->createMock(LearningReminderProcessor::class);
        $processor->expects(self::never())->method('process');
        $lock = $this->successfulLock();

        $tester = $this->tester($processor, $lock, at: '1900-01-01 00:00:00 UTC');
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Sélectionnés : 0', $tester->getDisplay());
        self::assertStringContainsString('En erreur : 0', $tester->getDisplay());
    }

    public function testSeveralRemindersProduceAllOutcomeCountersWithoutPersonalData(): void
    {
        $this->createReminder('2000-01-01 08:00:00 UTC', 'alice-secret@example.com', 'AliceSecret');
        $this->createReminder('2000-01-01 09:00:00 UTC', 'bob-secret@example.com', 'BobSecret');
        $this->createReminder('2000-01-01 10:00:00 UTC', 'claire-secret@example.com', 'ClaireSecret');
        $this->entityManager->flush();
        $processor = $this->createMock(LearningReminderProcessor::class);
        $processor
            ->expects(self::exactly(3))
            ->method('process')
            ->willReturnOnConsecutiveCalls(
                LearningReminderProcessingOutcome::SENT,
                LearningReminderProcessingOutcome::RESCHEDULED,
                LearningReminderProcessingOutcome::DISABLED,
            );

        $tester = $this->tester($processor, $this->successfulLock());
        $status = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Sélectionnés : 3', $display);
        self::assertStringContainsString('Envoyés : 1', $display);
        self::assertStringContainsString('Replanifiés : 1', $display);
        self::assertStringContainsString('Désactivés : 1', $display);
        self::assertStringNotContainsString('alice-secret@example.com', $display);
        self::assertStringNotContainsString('AliceSecret', $display);
    }

    public function testSeveralBatchesUseCursorCapturedBeforeReminderMutation(): void
    {
        for ($index = 0; $index < 101; ++$index) {
            $this->createReminder(
                '2000-02-01 08:00:00 UTC',
                sprintf('batch-%03d@example.com', $index),
                sprintf('Batch%03d', $index),
            );
        }

        $this->entityManager->flush();
        $processed = 0;
        $processor = $this->createMock(LearningReminderProcessor::class);
        $processor
            ->expects(self::exactly(101))
            ->method('process')
            ->willReturnCallback(static function (LearningReminder $reminder) use (&$processed): LearningReminderProcessingOutcome {
                ++$processed;
                $reminder->scheduleNextRunAt(
                    new \DateTimeImmutable('2001-02-01 08:00:00 UTC'),
                    new \DateTimeImmutable('2000-02-02 12:00:00 UTC'),
                );

                return LearningReminderProcessingOutcome::RESCHEDULED;
            });

        $tester = $this->tester(
            $processor,
            $this->successfulLock(),
            at: '2000-02-02 12:00:00 UTC',
        );
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(101, $processed);
        self::assertStringContainsString('Sélectionnés : 101', $tester->getDisplay());
        self::assertStringContainsString('Replanifiés : 101', $tester->getDisplay());
    }

    public function testIndividualFailureContinuesAndIsNotRetriedInSameExecution(): void
    {
        $first = $this->createReminder('2000-03-01 08:00:00 UTC', 'first-secret@example.com', 'FirstSecret');
        $this->createReminder('2000-03-01 09:00:00 UTC', 'second-secret@example.com', 'SecondSecret');
        $this->entityManager->flush();
        $calls = 0;
        $processor = $this->createMock(LearningReminderProcessor::class);
        $processor
            ->expects(self::exactly(2))
            ->method('process')
            ->willReturnCallback(static function () use (&$calls): LearningReminderProcessingOutcome {
                ++$calls;

                if (1 === $calls) {
                    throw new \RuntimeException('Test processing failure');
                }

                return LearningReminderProcessingOutcome::SENT;
            });

        $tester = $this->tester(
            $processor,
            $this->successfulLock(),
            at: '2000-03-02 12:00:00 UTC',
        );
        $status = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(2, $calls);
        self::assertStringContainsString(sprintf('Rappel #%d en erreur.', $first->getId()), $display);
        self::assertStringContainsString('Envoyés : 1', $display);
        self::assertStringContainsString('En erreur : 1', $display);
        self::assertStringNotContainsString('first-secret@example.com', $display);
        self::assertStringNotContainsString('FirstSecret', $display);
    }

    public function testFlushFailureStopsImmediatelyAndReturnsFailure(): void
    {
        $this->createReminder('2000-04-01 08:00:00 UTC');
        $this->createReminder('2000-04-01 09:00:00 UTC');
        $this->entityManager->flush();
        $processor = $this->createMock(LearningReminderProcessor::class);
        $processor
            ->expects(self::once())
            ->method('process')
            ->willReturn(LearningReminderProcessingOutcome::SENT);
        $failingEntityManager = $this->createMock(EntityManagerInterface::class);
        $failingEntityManager
            ->expects(self::once())
            ->method('flush')
            ->willThrowException(new \RuntimeException('Test flush failure'));

        $tester = $this->tester(
            $processor,
            $this->successfulLock(),
            $failingEntityManager,
            '2000-04-02 12:00:00 UTC',
        );
        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Sélectionnés : 2', $tester->getDisplay());
        self::assertStringContainsString('En erreur : 1', $tester->getDisplay());
    }

    public function testAlreadyHeldLockReturnsSuccessWithoutSelectionOrRelease(): void
    {
        $lock = $this->createMock(LearningReminderDispatchLock::class);
        $lock->expects(self::once())->method('acquire')->willReturn(false);
        $lock->expects(self::never())->method('release');
        $processor = $this->createMock(LearningReminderProcessor::class);
        $processor->expects(self::never())->method('process');
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::never())->method('now');

        $tester = $this->tester($processor, $lock, clock: $clock);
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('déjà en cours', $tester->getDisplay());
        self::assertStringContainsString('Sélectionnés : 0', $tester->getDisplay());
    }

    public function testAcquisitionExceptionReturnsFailureWithoutProcessingOrRelease(): void
    {
        $lock = $this->createMock(LearningReminderDispatchLock::class);
        $lock
            ->expects(self::once())
            ->method('acquire')
            ->willThrowException(new \RuntimeException('Test acquisition failure'));
        $lock->expects(self::never())->method('release');
        $processor = $this->createMock(LearningReminderProcessor::class);
        $processor->expects(self::never())->method('process');
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::never())->method('now');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $entityManager->expects(self::never())->method('clear');

        $tester = $this->tester($processor, $lock, $entityManager, clock: $clock);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Sélectionnés : 0', $tester->getDisplay());
        self::assertStringContainsString('Envoyés : 0', $tester->getDisplay());
    }

    public function testReleaseFailureMakesSuccessfulProcessingFail(): void
    {
        $lock = $this->createMock(LearningReminderDispatchLock::class);
        $lock->expects(self::once())->method('acquire')->willReturn(true);
        $lock->expects(self::once())->method('release')->willReturn(false);
        $processor = $this->createStub(LearningReminderProcessor::class);

        $tester = $this->tester($processor, $lock, at: '1900-01-01 00:00:00 UTC');

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    public function testInfrastructureExceptionStillReleasesLock(): void
    {
        $lock = $this->successfulLock();
        $processor = $this->createStub(LearningReminderProcessor::class);
        $clock = $this->createMock(ClockInterface::class);
        $clock
            ->expects(self::once())
            ->method('now')
            ->willThrowException(new \RuntimeException('Test clock failure'));

        $tester = $this->tester($processor, $lock, clock: $clock);

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    private function tester(
        LearningReminderProcessor $processor,
        LearningReminderDispatchLock $lock,
        ?EntityManagerInterface $entityManager = null,
        string $at = '2000-01-02 12:00:00 UTC',
        ?ClockInterface $clock = null,
    ): CommandTester {
        $clock ??= $this->createConfiguredStub(ClockInterface::class, [
            'now' => new \DateTimeImmutable($at),
        ]);

        return new CommandTester(new SendLearningRemindersCommand(
            $this->repository,
            $processor,
            $lock,
            $entityManager ?? $this->entityManager,
            $clock,
            $this->createStub(LoggerInterface::class),
        ));
    }

    private function successfulLock(): LearningReminderDispatchLock&MockObject
    {
        $lock = $this->createMock(LearningReminderDispatchLock::class);
        $lock->expects(self::once())->method('acquire')->willReturn(true);
        $lock->expects(self::once())->method('release')->willReturn(true);

        return $lock;
    }

    private function createReminder(
        string $nextRunAt,
        string $email = 'command-reminder@example.com',
        string $firstname = 'CommandSecret',
    ): LearningReminder {
        $suffix = bin2hex(random_bytes(8));
        $phone = (new PhoneNumber())
            ->setCountryCode(33)
            ->setNationalNumber('612345678');
        $user = (new User())
            ->setEmail(str_replace('@', sprintf('-%s@', $suffix), $email))
            ->setPassword('test-password')
            ->setFirstname($firstname)
            ->setLastname('Test')
            ->setAddress('1 rue du Test')
            ->setPostalCode('75001')
            ->setCity('Paris')
            ->setPhone($phone);
        $nextRun = new \DateTimeImmutable($nextRunAt);
        $reminder = LearningReminder::create(
            $user,
            LearningReminderFrequency::DAILY,
            new \DateTimeImmutable('08:00:00 UTC'),
            [],
            null,
            'UTC',
            $nextRun,
            $nextRun->modify('-1 day'),
        );

        $this->entityManager->persist($user);
        $this->entityManager->persist($reminder);
        $this->users[] = $user;

        return $reminder;
    }
}
