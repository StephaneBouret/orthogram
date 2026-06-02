<?php

namespace App\Services;

use App\Entity\Subscription;
use App\Enum\SubscriptionStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SubscriptionAdminActionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SendMailService $sendMailService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {}

    public function grantFreeYear(Subscription $subscription): bool
    {
        $this->assertStatusAllowed($subscription, [
            SubscriptionStatus::ACTIVE,
            SubscriptionStatus::EXPIRED,
            SubscriptionStatus::SUSPENDED,
        ], 'Seuls les abonnements actifs, expirés ou suspendus peuvent bénéficier de ce geste commercial.');

        $subscription
            ->setPriceCents(0)
            ->setTitle('Abonnement offert - 1 an')
            ->setDescription('Abonnement offert pour une durée d\'un an dans le cadre d\'un geste commercial.')
            ->activateForOneYear(new \DateTimeImmutable(), 'COMMERCIAL_GESTURE');

        return $this->flushAndNotify(
            $subscription,
            'Votre abonnement Orthogram est offert pendant 1 an',
            'Un accès offert pendant 1 an',
            'Votre abonnement Orthogram vient d\'être prolongé gratuitement pour une durée d\'un an.'
        );
    }

    public function grantLifetime(Subscription $subscription): bool
    {
        $this->assertStatusAllowed($subscription, [
            SubscriptionStatus::ACTIVE,
            SubscriptionStatus::EXPIRED,
            SubscriptionStatus::SUSPENDED,
        ], 'Seuls les abonnements actifs, expirés ou suspendus peuvent bénéficier de ce geste commercial.');

        $subscription
            ->setPriceCents(0)
            ->setTitle('Abonnement offert - À vie')
            ->setDescription('Abonnement offert à vie dans le cadre d\'un geste commercial.')
            ->activateLifetime('COMMERCIAL_GESTURE');

        return $this->flushAndNotify(
            $subscription,
            'Votre abonnement Orthogram est offert à vie',
            'Un accès offert à vie',
            'Votre abonnement Orthogram vient d\'être transformé en accès offert à vie.'
        );
    }

    public function suspend(Subscription $subscription): bool
    {
        $this->assertStatusAllowed(
            $subscription,
            [SubscriptionStatus::ACTIVE],
            'Seuls les abonnements actifs peuvent être suspendus.'
        );

        $subscription->suspend();

        return $this->flushAndNotify(
            $subscription,
            'Votre abonnement Orthogram est suspendu',
            'Abonnement suspendu',
            'Votre abonnement Orthogram vient d\'être suspendu. Si vous pensez qu\'il s\'agit d\'une erreur, vous pouvez contacter le support.'
        );
    }

    public function cancel(Subscription $subscription): bool
    {
        $this->assertStatusAllowed($subscription, [
            SubscriptionStatus::ACTIVE,
            SubscriptionStatus::SUSPENDED,
        ], 'Seuls les abonnements actifs ou suspendus peuvent être annulés.');

        $subscription->cancel();

        return $this->flushAndNotify(
            $subscription,
            'Votre abonnement Orthogram est annulé',
            'Abonnement annulé',
            'Votre abonnement Orthogram vient d\'être annulé.'
        );
    }

    public function reactivate(Subscription $subscription): bool
    {
        $this->assertStatusAllowed(
            $subscription,
            [SubscriptionStatus::SUSPENDED],
            'Seuls les abonnements suspendus peuvent être réactivés.'
        );

        if (
            !$subscription->isLifetime()
            && $subscription->getEndsAt() !== null
            && $subscription->getEndsAt() < new \DateTimeImmutable()
        ) {
            throw new \RuntimeException(
                'Cet abonnement est arrivé à expiration pendant sa suspension. Il faut plutôt offrir 1 an ou un accès à vie.'
            );
        }

        $subscription->reactivate();

        return $this->flushAndNotify(
            $subscription,
            'Votre abonnement Orthogram est réactivé',
            'Abonnement réactivé',
            'Votre abonnement Orthogram vient d\'être réactivé. Vous pouvez de nouveau accéder à votre espace.'
        );
    }

    /**
     * @param SubscriptionStatus[] $allowedStatuses
     */
    private function assertStatusAllowed(
        Subscription $subscription,
        array $allowedStatuses,
        string $message
    ): void {
        if (!in_array($subscription->getEffectiveStatus(), $allowedStatuses, true)) {
            throw new \RuntimeException($message);
        }
    }

    private function flushAndNotify(
        Subscription $subscription,
        string $subject,
        string $title,
        string $message
    ): bool {
        $this->em->flush();

        try {
            $this->sendMailService->sendMail(
                'Orthogram',
                (string) $subscription->getEmail(),
                $subject,
                'subscription_admin_action',
                [
                    'subscription' => $subscription,
                    'title' => $title,
                    'message' => $message,
                    'accountUrl' => $this->urlGenerator->generate(
                        'app_user_subscription_show',
                        [],
                        UrlGeneratorInterface::ABSOLUTE_URL
                    ),
                ],
                null
            );
        } catch (\Throwable $e) {
            $this->logger->error('Erreur lors de l\'envoi de l\'email d\'action admin abonnement.', [
                'subscriptionId' => $subscription->getId(),
                'email' => $subscription->getEmail(),
                'exception' => $e,
            ]);

            return false;
        }

        return true;
    }
}
