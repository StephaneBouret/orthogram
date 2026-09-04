<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\LearningReminderEligibility;
use App\Enum\SubscriptionStatus;
use App\Enum\UserAccountStatus;
use App\Services\LearningReminderEligibilityChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LearningReminderEligibilityCheckerTest extends TestCase
{
    private LearningReminderEligibilityChecker $checker;
    private \DateTimeImmutable $at;

    protected function setUp(): void
    {
        $this->checker = new LearningReminderEligibilityChecker();
        $this->at = new \DateTimeImmutable('2026-09-04 12:00:00 UTC');
    }

    public function testDeletedOrAnonymizedUserIsPermanentlyIneligible(): void
    {
        $deleted = $this->user()->setAccountStatus(UserAccountStatus::DELETED);
        $anonymized = $this->user()->setAnonymizedAt($this->at);

        self::assertSame(
            LearningReminderEligibility::PERMANENTLY_INELIGIBLE,
            $this->checker->check($deleted, $this->at),
        );
        self::assertSame(
            LearningReminderEligibility::PERMANENTLY_INELIGIBLE,
            $this->checker->check($anonymized, $this->at),
        );
    }

    public function testMissingOrInvalidEmailIsPermanentlyIneligibleEvenForAdmin(): void
    {
        $missingEmail = (new User())->setRoles(['ROLE_ADMIN']);
        $invalidEmail = $this->user('invalid-email')->setRoles(['ROLE_ADMIN']);

        self::assertSame(
            LearningReminderEligibility::PERMANENTLY_INELIGIBLE,
            $this->checker->check($missingEmail, $this->at),
        );
        self::assertSame(
            LearningReminderEligibility::PERMANENTLY_INELIGIBLE,
            $this->checker->check($invalidEmail, $this->at),
        );
    }

    public function testAdminIsEligibleRegardlessOfAccountStatus(): void
    {
        $admin = $this->user()
            ->setRoles(['ROLE_ADMIN'])
            ->setAccountStatus(UserAccountStatus::SUSPENDED);

        self::assertSame(
            LearningReminderEligibility::ELIGIBLE,
            $this->checker->check($admin, $this->at),
        );
    }

    #[DataProvider('temporaryAccountStatusProvider')]
    public function testNonAdminWithNonActiveAccountIsTemporarilyIneligible(
        UserAccountStatus $status,
    ): void {
        $user = $this->user()->setAccountStatus($status);
        $user->addSubscription($this->activeSubscription());

        self::assertSame(
            LearningReminderEligibility::TEMPORARILY_INELIGIBLE,
            $this->checker->check($user, $this->at),
        );
    }

    /**
     * @return iterable<string, array{UserAccountStatus}>
     */
    public static function temporaryAccountStatusProvider(): iterable
    {
        yield 'inactive' => [UserAccountStatus::INACTIVE];
        yield 'suspended' => [UserAccountStatus::SUSPENDED];
        yield 'pending verification' => [UserAccountStatus::PENDING_VERIFICATION];
    }

    public function testNormalUserWithActiveSubscriptionAtGivenInstantIsEligible(): void
    {
        $user = $this->user();
        $user->addSubscription($this->activeSubscription());

        self::assertSame(
            LearningReminderEligibility::ELIGIBLE,
            $this->checker->check($user, $this->at),
        );
    }

    public function testNormalUserWithoutActiveSubscriptionAtGivenInstantIsTemporarilyIneligible(): void
    {
        $withoutSubscription = $this->user();
        $expiredSubscription = $this->user();
        $expiredSubscription->addSubscription($this->activeSubscription()->setEndsAt($this->at->modify('-1 second')));
        $futureSubscription = $this->user();
        $futureSubscription->addSubscription($this->activeSubscription()->setStartsAt($this->at->modify('+1 second')));
        $suspendedSubscription = $this->user();
        $suspendedSubscription->addSubscription(
            $this->activeSubscription()->setStatus(SubscriptionStatus::SUSPENDED),
        );

        foreach ([$withoutSubscription, $expiredSubscription, $futureSubscription, $suspendedSubscription] as $user) {
            self::assertSame(
                LearningReminderEligibility::TEMPORARILY_INELIGIBLE,
                $this->checker->check($user, $this->at),
            );
        }
    }

    public function testSubscriptionIsActiveAtBoundariesAndIsActiveDelegatesToIt(): void
    {
        $subscription = $this->activeSubscription()
            ->setStartsAt($this->at)
            ->setEndsAt($this->at);

        self::assertTrue($subscription->isActiveAt($this->at));
        self::assertFalse($subscription->isActiveAt($this->at->modify('-1 second')));
        self::assertFalse($subscription->isActiveAt($this->at->modify('+1 second')));

        $lifetime = $this->activeSubscription()->setIsLifetime(true);

        self::assertTrue($lifetime->isActive());
    }

    private function user(string $email = 'learner@example.com'): User
    {
        return (new User())->setEmail($email);
    }

    private function activeSubscription(): Subscription
    {
        return (new Subscription())
            ->setStatus(SubscriptionStatus::ACTIVE)
            ->setIsLifetime(false)
            ->setStartsAt($this->at->modify('-1 day'))
            ->setEndsAt($this->at->modify('+1 day'));
    }
}
