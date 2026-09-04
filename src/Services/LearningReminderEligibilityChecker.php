<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\LearningReminderEligibility;

final class LearningReminderEligibilityChecker
{
    public function check(User $user, \DateTimeImmutable $at): LearningReminderEligibility
    {
        if ($user->isAnonymized() || null !== $user->getDeletedAt()) {
            return LearningReminderEligibility::PERMANENTLY_INELIGIBLE;
        }

        $email = $user->getEmail();

        if (null === $email || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return LearningReminderEligibility::PERMANENTLY_INELIGIBLE;
        }

        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return LearningReminderEligibility::ELIGIBLE;
        }

        if (!$user->isAccountActive()) {
            return LearningReminderEligibility::TEMPORARILY_INELIGIBLE;
        }

        $hasActiveSubscription = $user->getSubscriptions()->exists(
            static fn (int|string $key, Subscription $subscription): bool => $subscription->isActiveAt($at),
        );

        return $hasActiveSubscription
            ? LearningReminderEligibility::ELIGIBLE
            : LearningReminderEligibility::TEMPORARILY_INELIGIBLE;
    }
}
