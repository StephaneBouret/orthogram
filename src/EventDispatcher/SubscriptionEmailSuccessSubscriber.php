<?php

namespace App\EventDispatcher;

use App\Event\SubscriptionSuccessEvent;
use App\Services\SendMailService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SubscriptionEmailSuccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SendMailService $sendMail,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SubscriptionSuccessEvent::NAME => 'onSubscriptionSuccess',
        ];
    }

    public function onSubscriptionSuccess(SubscriptionSuccessEvent $event): void
    {
        $subscription = $event->getSubscription();
        $user = $subscription->getUser();

        $subscriptionUrl = $this->urlGenerator->generate(
            'app_subscription_show',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        if ($user && $user->getEmail()) {
            $this->sendMail->sendMail(
                'Votre abonnement praticien est maintenant actif',
                $user->getEmail(),
                'Votre abonnement a bien été activé',
                'subscription_success_user',
                [
                    'subscription' => $subscription,
                    'user' => $user,
                    'subscriptionUrl' => $subscriptionUrl,
                ],
                null
            );
        }
    }
}
