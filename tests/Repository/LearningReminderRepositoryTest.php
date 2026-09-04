<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\LearningReminder;
use App\Entity\User;
use App\Enum\LearningReminderFrequency;
use App\Repository\LearningReminderRepository;
use Doctrine\ORM\EntityManagerInterface;
use libphonenumber\PhoneNumber;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LearningReminderRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private LearningReminderRepository $repository;

    /** @var list<LearningReminder> */
    private array $reminders = [];

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
        if ($this->entityManager->isOpen()) {
            foreach ($this->reminders as $reminder) {
                if ($this->entityManager->contains($reminder)) {
                    $this->entityManager->remove($reminder);
                }
            }

            foreach ($this->users as $user) {
                if ($this->entityManager->contains($user)) {
                    $this->entityManager->remove($user);
                }
            }

            $this->entityManager->flush();
        }

        parent::tearDown();
    }

    public function testFindDueBatchSelectsOnlyEnabledScheduledRemindersAtOrBeforeLimit(): void
    {
        $before = $this->createReminder('2000-01-10 09:59:59 UTC');
        $atLimit = $this->createReminder('2000-01-10 10:00:00 UTC');
        $this->createReminder('2000-01-10 10:00:01 UTC');
        $this->createReminder('2000-01-10 09:00:00 UTC', enabled: false);
        $this->createReminder('2000-01-10 08:00:00 UTC', withoutNextRunAt: true);

        $result = $this->repository->findDueBatch(
            new \DateTimeImmutable('2000-01-10 10:00:00 UTC'),
        );

        self::assertSame([$before, $atLimit], $result);
    }

    public function testFindDueBatchOrdersByNextRunAtThenIdAndAppliesLimit(): void
    {
        $first = $this->createReminder('2000-02-10 08:00:00 UTC');
        $second = $this->createReminder('2000-02-10 09:00:00 UTC');
        $third = $this->createReminder('2000-02-10 09:00:00 UTC');
        $this->createReminder('2000-02-10 10:00:00 UTC');

        $result = $this->repository->findDueBatch(
            new \DateTimeImmutable('2000-02-10 12:00:00 UTC'),
            3,
        );

        self::assertSame([$first, $second, $third], $result);
    }

    public function testFindDueBatchUsesOriginalCursorWithoutDuplicateOrOmission(): void
    {
        $expected = [
            $this->createReminder('2000-03-10 08:00:00 UTC'),
            $this->createReminder('2000-03-10 09:00:00 UTC'),
            $this->createReminder('2000-03-10 09:00:00 UTC'),
            $this->createReminder('2000-03-10 10:00:00 UTC'),
            $this->createReminder('2000-03-10 11:00:00 UTC'),
        ];
        $dueAt = new \DateTimeImmutable('2000-03-10 12:00:00 UTC');

        $firstPage = $this->repository->findDueBatch($dueAt, 2);
        $cursorNextRunAt = $firstPage[1]->getNextRunAt();
        $cursorId = $firstPage[1]->getId();

        self::assertInstanceOf(\DateTimeImmutable::class, $cursorNextRunAt);
        self::assertNotNull($cursorId);

        $firstPage[1]->scheduleNextRunAt(
            new \DateTimeImmutable('2001-03-10 09:00:00 UTC'),
            new \DateTimeImmutable('2000-03-10 09:30:00 UTC'),
        );
        $this->entityManager->flush();

        $secondPage = $this->repository->findDueBatch($dueAt, 2, $cursorNextRunAt, $cursorId);
        $secondCursorNextRunAt = $secondPage[1]->getNextRunAt();
        $secondCursorId = $secondPage[1]->getId();

        self::assertInstanceOf(\DateTimeImmutable::class, $secondCursorNextRunAt);
        self::assertNotNull($secondCursorId);

        $thirdPage = $this->repository->findDueBatch($dueAt, 2, $secondCursorNextRunAt, $secondCursorId);
        $actual = [...$firstPage, ...$secondPage, ...$thirdPage];

        self::assertSame($expected, $actual);
        self::assertSame(
            array_map(static fn (LearningReminder $reminder): ?int => $reminder->getId(), $expected),
            array_values(array_unique(array_map(
                static fn (LearningReminder $reminder): ?int => $reminder->getId(),
                $actual,
            ))),
        );
    }

    public function testFindDueBatchRejectsNonPositiveLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository->findDueBatch(new \DateTimeImmutable('2000-04-10 UTC'), 0);
    }

    public function testFindDueBatchRejectsPartialCursor(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository->findDueBatch(
            new \DateTimeImmutable('2000-04-10 UTC'),
            afterNextRunAt: new \DateTimeImmutable('2000-04-09 UTC'),
        );
    }

    private function createReminder(
        string $nextRunAt,
        bool $enabled = true,
        bool $withoutNextRunAt = false,
    ): LearningReminder {
        $suffix = bin2hex(random_bytes(8));
        $phone = (new PhoneNumber())
            ->setCountryCode(33)
            ->setNationalNumber('612345678');
        $user = (new User())
            ->setEmail(sprintf('repository-reminder-%s@example.com', $suffix))
            ->setPassword('test-password')
            ->setFirstname('Camille')
            ->setLastname('Test')
            ->setAddress('1 rue du Test')
            ->setPostalCode('75001')
            ->setCity('Paris')
            ->setPhone($phone);
        $nextRun = new \DateTimeImmutable($nextRunAt);
        $createdAt = $nextRun->modify('-1 day');
        $reminder = LearningReminder::create(
            $user,
            LearningReminderFrequency::DAILY,
            new \DateTimeImmutable('08:00:00 UTC'),
            [],
            null,
            'UTC',
            $nextRun,
            $createdAt,
        );

        if (!$enabled || $withoutNextRunAt) {
            $reminder->disable($createdAt->modify('+1 hour'));
        }

        if ($withoutNextRunAt) {
            (new \ReflectionProperty(LearningReminder::class, 'enabled'))->setValue($reminder, true);
        }

        $this->entityManager->persist($user);
        $this->entityManager->persist($reminder);
        $this->entityManager->flush();
        $this->users[] = $user;
        $this->reminders[] = $reminder;

        return $reminder;
    }
}
