<?php

namespace App\Controller\Subscription;

use App\Entity\Subscription;
use App\Entity\User;
use App\Stripe\StripeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/abonnement')]
final class SubscriptionConfirmController extends AbstractController
{
    public function __construct(private readonly StripeService $stripeService)
    {
    }

    #[IsGranted('ROLE_USER', message: 'Vous devez être connecté pour accéder à cette page')]
    #[Route('/confirm/{id}', name: 'app_subscription_confirm', methods: ['GET'])]
    public function __invoke(Subscription $subscription): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($subscription->getUser() !== $user) {
            $this->addFlash('warning', 'Accès refusé à cette souscription.');

            return $this->redirectToRoute('app_home');
        }

        if (!$subscription->isPending()) {
            if ($subscription->isActive()) {
                $this->addFlash('info', 'Cet abonnement est déjà actif.');
            } elseif ($subscription->isSuspended()) {
                $this->addFlash('warning', 'Cet abonnement est suspendu. Merci de contacter le support.');
            } elseif ($subscription->isCancelled()) {
                $this->addFlash('warning', 'Cette souscription a été annulée.');
            } elseif ($subscription->isExpired()) {
                $this->addFlash('warning', 'Cette souscription a expiré.');
            } else {
                $this->addFlash('warning', 'Cette souscription ne peut pas être payée.');
            }

            return $this->redirectToRoute('app_home');
        }

        if (
            null === $subscription->getTermsAcceptedAt()
            || null === $subscription->getImmediateAccessRequestedAt()
            || null === $subscription->getWithdrawalRightWaivedAt()
        ) {
            $this->addFlash('warning', 'Veuillez confirmer les conditions de souscription avant de procéder au paiement.');

            return $this->redirectToRoute('app_subscription_checkout');
        }

        $paymentIntent = $this->stripeService->getPaymentIntentForSubscription($subscription);

        return $this->render('subscription/payment.html.twig', [
            'subscription' => $subscription,
            'clientSecret' => $paymentIntent->client_secret,
            'stripePublicKey' => $this->stripeService->getPublicKey(),
        ]);
    }
}
