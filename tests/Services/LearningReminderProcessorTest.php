<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Entity\LearningReminder;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\LearningReminderFrequency;
use App\Enum\LearningReminderProcessingOutcome;
use App\Enum\SubscriptionStatus;
use App\Services\LearningReminderEligibilityChecker;
use App\Services\LearningReminderNextRunCalculator;
use App\Services\LearningReminderProcessor;
use App\Services\LearningReminderViewService;
use App\Services\SendMailService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class LearningReminderProcessorTest extends TestCase
{
    private const PROCESSED_AT = '2026-09-03 12:00:00 UTC';

    public function testEligibleDailyReminderIsSentAndRescheduled(): void
    {
        $reminder = $this->reminder(LearningReminderFrequency::DAILY, user: $this->eligibleUser());
        $sendMail = $this->createMock(SendMailService::class);
        $sendMail
            ->expects(self::once())
            ->method('sendMail')
            ->with(
                'Orthogram',
                'camille@example.com',
                'C’est le moment de poursuivre votre formation Orthogram',
                'learning_reminder',
                self::callback(static function (array $context): bool {
                    self::assertSame('Camille', $context['firstname']);
                    self::assertSame('Tous les jours à 8 h', $context['summary']);
                    self::assertSame('https://orthogram.example.test/ma-formation', $context['trainingUrl']);

                    return true;
                }),
            );
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator
            ->expects(self::once())
            ->method('generate')
            ->with('app_user_training', [], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://orthogram.example.test/ma-formation');

        $outcome = $this->processor($sendMail, $urlGenerator)->process($reminder);

        self::assertSame(LearningReminderProcessingOutcome::SENT, $outcome);
        self::assertSame(self::PROCESSED_AT, $reminder->getLastSentAt()?->format('Y-m-d H:i:s T'));
        self::assertSame('2026-09-04 06:00:00 UTC', $reminder->getNextRunAt()?->format('Y-m-d H:i:s T'));
        self::assertTrue($reminder->isEnabled());
    }

    public function testEligibleWeeklyReminderIsSentAndRescheduled(): void
    {
        $reminder = $this->reminder(
            LearningReminderFrequency::WEEKLY,
            [5],
            $this->eligibleUser(),
        );
        $sendMail = $this->createMock(SendMailService::class);
        $sendMail->expects(self::once())->method('sendMail');

        $outcome = $this->processor($sendMail)->process($reminder);

        self::assertSame(LearningReminderProcessingOutcome::SENT, $outcome);
        self::assertSame(self::PROCESSED_AT, $reminder->getLastSentAt()?->format('Y-m-d H:i:s T'));
        self::assertSame('2026-09-04 06:00:00 UTC', $reminder->getNextRunAt()?->format('Y-m-d H:i:s T'));
    }

    public function testEligibleOnceReminderIsSentThenDisabled(): void
    {
        $reminder = $this->reminder(LearningReminderFrequency::ONCE, user: $this->eligibleUser());
        $sendMail = $this->createMock(SendMailService::class);
        $sendMail->expects(self::once())->method('sendMail');

        $outcome = $this->processor($sendMail)->process($reminder);

        self::assertSame(LearningReminderProcessingOutcome::SENT, $outcome);
        self::assertSame(self::PROCESSED_AT, $reminder->getLastSentAt()?->format('Y-m-d H:i:s T'));
        self::assertFalse($reminder->isEnabled());
        self::assertNull($reminder->getNextRunAt());
    }

    public function testTemporarilyIneligibleRecurringReminderIsRescheduledWithoutEmail(): void
    {
        $reminder = $this->reminder(LearningReminderFrequency::DAILY, user: $this->temporaryUser());
        $sendMail = $this->createMock(SendMailService::class);
        $sendMail->expects(self::never())->method('sendMail');

        $outcome = $this->processor($sendMail)->process($reminder);

        self::assertSame(LearningReminderProcessingOutcome::RESCHEDULED, $outcome);
        self::assertSame('2026-09-04 06:00:00 UTC', $reminder->getNextRunAt()?->format('Y-m-d H:i:s T'));
        self::assertNull($reminder->getLastSentAt());
        self::assertTrue($reminder->isEnabled());
    }

    public function testTemporarilyIneligibleOnceReminderIsDisabledWithoutEmail(): void
    {
        $reminder = $this->reminder(LearningReminderFrequency::ONCE, user: $this->temporaryUser());
        $sendMail = $this->createMock(SendMailService::class);
        $sendMail->expects(self::never())->method('sendMail');

        $outcome = $this->processor($sendMail)->process($reminder);

        self::assertSame(LearningReminderProcessingOutcome::DISABLED, $outcome);
        self::assertFalse($reminder->isEnabled());
        self::assertNull($reminder->getNextRunAt());
        self::assertNull($reminder->getLastSentAt());
    }

    public function testPermanentlyIneligibleReminderIsDisabledWithoutEmail(): void
    {
        $user = $this->eligibleUser()->setAnonymizedAt(new \DateTimeImmutable(self::PROCESSED_AT));
        $reminder = $this->reminder(LearningReminderFrequency::DAILY, user: $user);
        $sendMail = $this->createMock(SendMailService::class);
        $sendMail->expects(self::never())->method('sendMail');

        $outcome = $this->processor($sendMail)->process($reminder);

        self::assertSame(LearningReminderProcessingOutcome::DISABLED, $outcome);
        self::assertFalse($reminder->isEnabled());
        self::assertNull($reminder->getNextRunAt());
        self::assertNull($reminder->getLastSentAt());
    }

    public function testMailerFailureIsPropagatedAndLeavesReminderUnchanged(): void
    {
        $reminder = $this->reminder(LearningReminderFrequency::DAILY, user: $this->eligibleUser());
        $before = $this->state($reminder);
        $sendMail = $this->createMock(SendMailService::class);
        $sendMail
            ->expects(self::once())
            ->method('sendMail')
            ->willThrowException(new \RuntimeException('Mailer unavailable'));

        try {
            $this->processor($sendMail)->process($reminder);
            self::fail('L’exception du mailer aurait dû remonter.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Mailer unavailable', $exception->getMessage());
        }

        self::assertSame($before, $this->state($reminder));
    }

    public function testFailureBeforeEmailLeavesReminderUnchangedAndDoesNotCallMailer(): void
    {
        $reminder = $this->reminder(LearningReminderFrequency::DAILY, user: $this->eligibleUser());
        $before = $this->state($reminder);
        $sendMail = $this->createMock(SendMailService::class);
        $sendMail->expects(self::never())->method('sendMail');
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator
            ->expects(self::once())
            ->method('generate')
            ->willThrowException(new \RuntimeException('Router unavailable'));

        try {
            $this->processor($sendMail, $urlGenerator)->process($reminder);
            self::fail('L’exception du routeur aurait dû remonter.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Router unavailable', $exception->getMessage());
        }

        self::assertSame($before, $this->state($reminder));
    }

    public function testProcessorHasNoEntityManagerDependency(): void
    {
        $constructor = (new \ReflectionClass(LearningReminderProcessor::class))->getConstructor();

        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            self::assertNotSame(EntityManagerInterface::class, (string) $parameter->getType());
        }
    }

    private function processor(
        SendMailService&MockObject $sendMail,
        ?UrlGeneratorInterface $urlGenerator = null,
    ): LearningReminderProcessor {
        $clock = $this->createMock(ClockInterface::class);
        $clock
            ->expects(self::once())
            ->method('now')
            ->willReturn(new \DateTimeImmutable(self::PROCESSED_AT));
        if (null === $urlGenerator) {
            $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
            $urlGenerator
                ->method('generate')
                ->willReturn('https://orthogram.example.test/ma-formation');
        }

        return new LearningReminderProcessor(
            new LearningReminderEligibilityChecker(),
            new LearningReminderNextRunCalculator(),
            new LearningReminderViewService(),
            $sendMail,
            $urlGenerator,
            $clock,
        );
    }

    /**
     * @param list<int> $weekdays
     */
    private function reminder(
        LearningReminderFrequency $frequency,
        array $weekdays = [],
        ?User $user = null,
    ): LearningReminder {
        return LearningReminder::create(
            $user ?? $this->eligibleUser(),
            $frequency,
            new \DateTimeImmutable('08:00:00 UTC'),
            $weekdays,
            LearningReminderFrequency::ONCE === $frequency
                ? new \DateTimeImmutable('2026-09-02 UTC')
                : null,
            'Europe/Paris',
            new \DateTimeImmutable('2026-09-02 06:00:00 UTC'),
            new \DateTimeImmutable('2026-09-01 06:00:00 UTC'),
        );
    }

    private function eligibleUser(): User
    {
        $user = (new User())
            ->setEmail('camille@example.com')
            ->setFirstname('Camille');
        $subscription = (new Subscription())
            ->setStatus(SubscriptionStatus::ACTIVE)
            ->setIsLifetime(true);
        $user->addSubscription($subscription);

        return $user;
    }

    private function temporaryUser(): User
    {
        return (new User())
            ->setEmail('camille@example.com')
            ->setFirstname('Camille');
    }

    /**
     * @return array{enabled: bool, nextRunAt: ?string, lastSentAt: ?string, updatedAt: string}
     */
    private function state(LearningReminder $reminder): array
    {
        return [
            'enabled' => $reminder->isEnabled(),
            'nextRunAt' => $reminder->getNextRunAt()?->format(\DateTimeInterface::ATOM),
            'lastSentAt' => $reminder->getLastSentAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $reminder->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
