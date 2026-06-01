<?php

namespace App\Controller\Subscription;

use App\Entity\Subscription;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/abonnement')]
final class SubscriptionPaymentStatusController extends AbstractController
{
    #[IsGranted('ROLE_USER', message: 'Vous devez être connecté pour accéder à cette page')]
    #[Route('/paiement/status/{id}', name: 'app_subscription_payment_status', methods: ['GET'])]
    public function __invoke(Subscription $subscription): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($subscription->getUser() !== $user) {
            return $this->json([
                'error' => 'Accès refusé.',
            ], JsonResponse::HTTP_FORBIDDEN);
        }

        $paymentState = 'processing';

        if ($subscription->isActive()) {
            $paymentState = 'success';
        } elseif ($subscription->isSuspended()) {
            $paymentState = 'suspended';
        } elseif ($subscription->isCancelled()) {
            $paymentState = 'cancelled';
        } elseif ($subscription->isExpired()) {
            $paymentState = 'expired';
        }

        return $this->json([
            'paymentState' => $paymentState,
            'statusLabel' => $subscription->getStatus()->label(),
            'paymentReference' => $subscription->getPaymentReference(),
        ]);
    }
}
