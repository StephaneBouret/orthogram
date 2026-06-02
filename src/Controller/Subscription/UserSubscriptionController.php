<?php

namespace App\Controller\Subscription;

use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Services\SubscriptionViewService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/mon-abonnement', name: 'app_user_subscription_show', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
final class UserSubscriptionController extends AbstractController
{
    public function __invoke(
        SubscriptionRepository $subscriptionRepository,
        SubscriptionViewService $subscriptionViewService
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $subscription = $subscriptionRepository->findCurrentForUser($user);

        $viewData = $subscriptionViewService->build($subscription);

        return $this->render('subscription/account.html.twig', [
            'subscription' => $subscription,
            ...$viewData,
        ]);
    }
}
