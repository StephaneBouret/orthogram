<?php

namespace App\Controller\Subscription;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SubscriptionStatus;
use App\Form\SubscriptionCheckoutFormType;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/abonnement')]
final class SubscriptionCheckoutController extends AbstractController
{
    #[IsGranted('ROLE_USER', message: 'Vous devez être connecté pour accéder à cette page')]
    #[Route('/checkout', name: 'app_subscription_checkout', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        SubscriptionRepository $subscriptionRepository,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // 1. Vérifier si un abonnement bloque
        $blockingSubscription = $subscriptionRepository->findActiveLifetimeOrSuspendedForUser($user);

        if ($blockingSubscription) {
            if ($blockingSubscription->isLifetime()) {
                $this->addFlash('info', 'Vous disposez déjà d\'un accès illimité.');
            } elseif ($blockingSubscription->isSuspended()) {
                $this->addFlash('warning', 'Votre abonnement est suspendu. Merci de contacter le support.');
            }

            return $this->redirectToRoute('app_home');
        }

        // 2. Récupérer un pending existant
        $subscription = $subscriptionRepository->findLatestPendingForUser($user);

        // 3. Sinon créer une nouvelle souscription
        if (!$subscription) {
            $subscription = (new Subscription())
                ->setUser($user)
                ->setEmail((string) $user->getEmail())
                ->setStatus(SubscriptionStatus::PENDING)
                ->setPriceCents(3000)
                ->setTitle('Abonnement annuel Orthogram')
                ->setDescription('Accès à la plateforme Orthogram pendant 1 an avec l\'ensemble des contenus et fonctionnalités inclus dans l\'abonnement.');

            $em->persist($subscription);
            $em->flush();
        }

        // 4. Formulaire
        $form = $this->createForm(SubscriptionCheckoutFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $subscription->markCheckoutConsents();
            $em->flush();

            return $this->redirectToRoute('app_subscription_confirm', [
                'id' => $subscription->getId(),
            ]);
        }

        return $this->render('subscription/checkout.html.twig', [
            'user' => $user,
            'subscription' => $subscription,
            'confirmationForm' => $form,
        ]);
    }
}
