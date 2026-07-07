<?php

namespace App\Stripe;

use App\Entity\Subscription;
use Stripe\StripeClient;

class StripeService
{
    private StripeClient $client;

    public function __construct(
        private readonly string $secretKey,
        private readonly string $publicKey,
    ) {
        $this->client = new StripeClient($this->secretKey);
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Méthode générique : création d'un PaymentIntent avec metadata et description.
     */
    public function createPaymentIntent(int $amount, array $metadata = [], ?string $description = null)
    {
        return $this->client->paymentIntents->create([
            'amount' => $amount,
            'currency' => 'eur',
            'payment_method_types' => ['card'], // 'paypal' à ajouter dans le tableau ['card', 'paypal'] si activé dans Stripe
            'metadata' => array_filter($metadata, static fn ($value) => null !== $value && '' !== $value),
            'description' => $description,
        ]);
    }

    /**
     * Souscription (Subscription) — PaymentIntent avec metadata et description dédiée.
     */
    public function getPaymentIntentForSubscription(Subscription $subscription)
    {
        $user = $subscription->getUser();

        return $this->createPaymentIntent(
            $subscription->getPriceCents(),
            [
                'kind' => 'subscription',
                'subscription_id' => (string) $subscription->getId(),
                'subscription_title' => $subscription->getTitle(),
                'user_id' => $user ? (string) $user->getId() : null,
                'user_email' => $user ? (string) $user->getEmail() : null,
                'status' => $subscription->getStatus()->value,
            ],
            sprintf(
                'Abonnement • %s',
                $subscription->getTitle() ?? 'Abonnement praticien'
            )
        );
    }
}
