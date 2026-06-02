<?php

namespace App\Services;

use App\Entity\Subscription;
use App\Services\SendMailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SubscriptionReminderService
{
    public function __construct(
        private readonly SendMailService $sendMailService,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urlGenerator
    ) {}

    public function sendReminder(Subscription $subscription, int $daysBeforeExpiration): void
    {
        $renewUrl = $this->urlGenerator->generate(
            'app_subscription_show',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $this->sendMailService->sendMail(
            sprintf('Votre abonnement expire dans %d jours', $daysBeforeExpiration),
            (string) $subscription->getEmail(),
            sprintf('Votre abonnement praticien expire dans %d jours', $daysBeforeExpiration),
            'subscription_expiration_reminder',
            [
                'subscription' => $subscription,
                'daysBeforeExpiration' => $daysBeforeExpiration,
                'renewUrl' => $renewUrl,
            ],
            null
        );

        if ($daysBeforeExpiration === 30) {
            $subscription->markReminder30Sent();
        }

        if ($daysBeforeExpiration === 15) {
            $subscription->markReminder15Sent();
        }

        $this->em->flush();
    }
}
