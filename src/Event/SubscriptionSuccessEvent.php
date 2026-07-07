<?php

namespace App\Event;

use App\Entity\Subscription;

class SubscriptionSuccessEvent
{
    public const NAME = 'subscription.success';

    public function __construct(
        private readonly Subscription $subscription,
    ) {
    }

    public function getSubscription(): Subscription
    {
        return $this->subscription;
    }
}
