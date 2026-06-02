<?php

namespace App\Services;

use App\Entity\Invitation;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\InvitationType;
use App\Enum\SubscriptionStatus;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class InvitationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvitationRepository $invitationRepository,
        private readonly SendMailService $sendMailService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function generateToken(): string
    {
        do {
            $token = bin2hex(random_bytes(32));
        } while ($this->invitationRepository->findOneBy(['token' => $token]));

        return $token;
    }

    public function initializeInvitation(Invitation $invitation): void
    {
        if (!$invitation->getToken()) {
            $invitation->setToken($this->generateToken());
        }

        if (!$invitation->getExpiresAt()) {
            $invitation->setExpiresAt(
                (new \DateTimeImmutable())->modify('+7 days')
            );
        }
    }

    public function createInvitation(
        string $email,
        InvitationType $type,
        ?\DateTimeImmutable $expiresAt = null
    ): Invitation {
        $invitation = new Invitation();
        $invitation
            ->setEmail($email)
            ->setType($type)
            ->setToken($this->generateToken())
            ->setExpiresAt($expiresAt);

        $this->em->persist($invitation);
        $this->em->flush();

        return $invitation;
    }

    public function getInvitationUrl(Invitation $invitation): string
    {
        return $this->urlGenerator->generate('app_invitation_claim', [
            'token' => $invitation->getToken(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function sendInvitation(Invitation $invitation): void
    {
        $invitationUrl = $this->getInvitationUrl($invitation);

        $this->sendMailService->sendMail(
            sprintf('Invitation - %s', $invitation->getType()->label()),
            $invitation->getEmail(),
            'Invitation à rejoindre l\'application',
            'invitation',
            [
                'invitation' => $invitation,
                'invitationUrl' => $invitationUrl,
            ],
            null
        );

        $invitation->markSent();
        $this->em->flush();
    }

    public function consumeInvitation(Invitation $invitation, User $user): Subscription
    {
        if (!$invitation->isValid()) {
            throw new \RuntimeException('Cette invitation est invalide, expirée ou déjà utilisée.');
        }

        $subscription = new Subscription();
        $subscription
            ->setUser($user)
            ->setEmail((string) $user->getEmail())
            ->setStatus(SubscriptionStatus::ACTIVE)
            ->setPriceCents(0)
            ->setTitle($invitation->getType()->subscriptionTitle())
            ->setDescription($invitation->getType()->subscriptionDescription())
            ->setPaymentReference('INVITATION');

        match ($invitation->getType()) {
            InvitationType::FREE_YEAR => $subscription->activateForOneYear(new \DateTimeImmutable(), 'INVITATION'),
            InvitationType::LIFETIME => $subscription->activateLifetime('INVITATION'),
        };

        $invitation->markUsed($user);

        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }
}
