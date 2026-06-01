<?php

namespace App\Controller;

use App\Entity\Subscription;
use App\Event\SubscriptionSuccessEvent;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly string $stripeWebhookSecret,
        private readonly LoggerInterface $logger
    ) {}

    #[Route('/stripe/webhook', name: 'app_stripe_webhook', methods: ['POST'])]
    public function __invoke(
        Request $request,
        SubscriptionRepository $subscriptionRepository,
        EntityManagerInterface $em,
        EventDispatcherInterface $dispatcher
    ): Response {
        $payload = $request->getContent();
        $signature = $request->headers->get('Stripe-Signature');

        if (!$signature) {
            return new Response('Signature Stripe manquante.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $event = Webhook::constructEvent(
                $payload,
                $signature,
                $this->stripeWebhookSecret
            );
        } catch (\UnexpectedValueException $e) {
            return new Response('Payload webhook invalide.', Response::HTTP_BAD_REQUEST);
        } catch (SignatureVerificationException $e) {
            return new Response('Signature webhook invalide.', Response::HTTP_BAD_REQUEST);
        }

        switch ($event->type) {
            case 'payment_intent.succeeded':
                /** @var \Stripe\PaymentIntent $paymentIntent */
                $paymentIntent = $event->data->object;

                $metadata = $paymentIntent->metadata ?? null;

                if (!$metadata || ($metadata->kind ?? null) !== 'subscription') {
                    return new Response('Event ignoré.', Response::HTTP_OK);
                }

                $subscriptionId = isset($metadata->subscription_id)
                    ? (int) $metadata->subscription_id
                    : null;

                if (!$subscriptionId) {
                    return new Response('subscription_id manquant.', Response::HTTP_BAD_REQUEST);
                }

                /** @var Subscription|null $subscription */
                $subscription = $subscriptionRepository->find($subscriptionId);

                if (!$subscription) {
                    return new Response('Souscription introuvable.', Response::HTTP_NOT_FOUND);
                }

                // Idempotence : si déjà active, on ne refait rien
                if ($subscription->isActive()) {
                    return new Response('Souscription déjà active.', Response::HTTP_OK);
                }

                // On ne traite que les pending
                if (!$subscription->isPending()) {
                    return new Response('Souscription non payable dans cet état.', Response::HTTP_OK);
                }

                if (
                    (int) $paymentIntent->amount !== $subscription->getPriceCents() ||
                    strtolower((string) $paymentIntent->currency) !== 'eur'
                ) {
                    $this->logger->warning('Stripe PaymentIntent incohérent pour une souscription.', [
                        'payment_intent' => $paymentIntent->id,
                        'subscription_id' => $subscription->getId(),
                        'payment_intent_amount' => $paymentIntent->amount,
                        'subscription_price_cents' => $subscription->getPriceCents(),
                        'payment_intent_currency' => $paymentIntent->currency,
                    ]);
                }

                $subscription->activateForOneYear(
                    new \DateTimeImmutable(),
                    $paymentIntent->id
                );

                $em->flush();

                try {
                    $dispatcher->dispatch(new SubscriptionSuccessEvent($subscription), SubscriptionSuccessEvent::NAME);
                } catch (\Throwable $e) {
                    $this->logger->error('Erreur lors de l’envoi de l’email de confirmation d’abonnement.', [
                        'exception' => $e,
                        'payment_intent' => $paymentIntent->id,
                        'subscription_id' => $subscription->getId(),
                    ]);
                }

                return new Response('Souscription activée.', Response::HTTP_OK);

            case 'payment_intent.payment_failed':
                /** @var \Stripe\PaymentIntent $paymentIntent */
                $paymentIntent = $event->data->object;

                $metadata = $paymentIntent->metadata ?? null;

                if (!$metadata || ($metadata->kind ?? null) !== 'subscription') {
                    return new Response('Event ignoré.', Response::HTTP_OK);
                }

                $subscriptionId = isset($metadata->subscription_id)
                    ? (int) $metadata->subscription_id
                    : null;

                if (!$subscriptionId) {
                    return new Response('subscription_id manquant.', Response::HTTP_BAD_REQUEST);
                }

                $subscription = $subscriptionRepository->find($subscriptionId);

                if (!$subscription) {
                    return new Response('Souscription introuvable.', Response::HTTP_NOT_FOUND);
                }

                // Ici on ne change pas forcément le statut.
                // On peut simplement conserver pending.
                // Plus tard, si tu veux, on pourra stocker un lastPaymentError.
                return new Response('Echec de paiement enregistré.', Response::HTTP_OK);
        }

        return new Response('Event non géré.', Response::HTTP_OK);
    }
}
