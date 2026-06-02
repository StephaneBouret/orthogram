<?php

namespace App\Controller\Subscription;

use App\Entity\User;
use App\Repository\SubscriptionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/abonnement', name: 'app_subscription_show', methods: ['GET'])]
final class SubscriptionShowController extends AbstractController
{
    public function __invoke(SubscriptionRepository $subscriptionRepository): Response
    {
        $currentSubscription = null;

        if ($this->getUser() instanceof User) {
            $currentSubscription = $subscriptionRepository->findCurrentForUser($this->getUser());
        }

        return $this->render('subscription/show.html.twig', [
            'checkoutPath' => $this->generateUrl('app_subscription_checkout'),
            'loginPath' => $this->generateUrl('app_login', [
                '_target_path' => $this->generateUrl('app_subscription_checkout'),
            ]),
            'currentSubscription' => $currentSubscription,
        ]);
    }
}
