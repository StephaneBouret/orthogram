<?php

namespace App\Controller\Subscription;

use App\Entity\Subscription;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/abonnement')]
final class SubscriptionPaymentSuccessController extends AbstractController
{
    #[IsGranted('ROLE_USER', message: 'Vous devez être connecté pour accéder à cette page')]
    #[Route('/paiement/success/{id}', name: 'app_subscription_payment_success', methods: ['GET'])]
    public function __invoke(Subscription $subscription): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($subscription->getUser() !== $user) {
            $this->addFlash('warning', 'Accès refusé à cette souscription.');

            return $this->redirectToRoute('app_home');
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

        return $this->render('subscription/payment_success.html.twig', [
            'subscription' => $subscription,
            'paymentState' => $paymentState,
        ]);
    }
}
