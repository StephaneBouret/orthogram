<?php

namespace App\Services;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class UserEmailChangeService
{
    private const CONFIRMATION_EXPIRATION_HOURS = 24;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly SendMailService $email,
        private readonly UserRepository $userRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function requestEmailChange(User $user, string $newEmail): void
    {
        $newEmail = $this->normalizeEmail($newEmail);
        $this->assertEmailCanBeRequested($user, $newEmail);

        $token = bin2hex(random_bytes(32));
        $now = new \DateTimeImmutable();
        $currentEmail = (string) $user->getEmail();

        $user
            ->setPendingEmail($newEmail)
            ->setEmailChangeToken($this->hashToken($token))
            ->setEmailChangeRequestedAt($now);

        $this->em->flush();

        $confirmationUrl = $this->urlGenerator->generate(
            'app_profile_email_confirm',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $context = [
            'user' => $user,
            'currentEmail' => $currentEmail,
            'pendingEmail' => $newEmail,
            'confirmationUrl' => $confirmationUrl,
            'expiresInHours' => self::CONFIRMATION_EXPIRATION_HOURS,
        ];

        try {
            $this->email->sendMail(
                'Orthogram',
                $newEmail,
                'Confirmez votre nouvelle adresse email',
                'email_change_confirmation',
                $context,
                null
            );
        } catch (\Throwable $exception) {
            $user->clearPendingEmailChange();
            $this->em->flush();

            $this->logger->error('Erreur lors de l\'envoi de l\'email de confirmation de changement d\'identifiant.', [
                'userId' => $user->getId(),
                'pendingEmail' => $newEmail,
                'exception' => $exception,
            ]);

            throw new \RuntimeException('L’email de confirmation n’a pas pu être envoyé. Merci de réessayer dans quelques instants.', previous: $exception);
        }

        try {
            $this->email->sendMail(
                'Orthogram',
                $currentEmail,
                'Demande de changement d\'identifiant',
                'email_change_notification',
                $context,
                null
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('Erreur lors de l\'envoi de la notification de changement d\'identifiant à l\'ancienne adresse.', [
                'userId' => $user->getId(),
                'currentEmail' => $currentEmail,
                'pendingEmail' => $newEmail,
                'exception' => $exception,
            ]);
        }
    }

    public function confirmEmailChange(User $user, string $token): string
    {
        $token = trim($token);
        if ('' === $token || $user->getEmailChangeToken() !== $this->hashToken($token)) {
            return 'invalid';
        }

        if ($this->isConfirmationExpired($user)) {
            $user->clearPendingEmailChange();
            $this->em->flush();

            return 'expired';
        }

        $pendingEmail = $user->getPendingEmail();
        if (null === $pendingEmail) {
            $user->clearPendingEmailChange();
            $this->em->flush();

            return 'invalid';
        }

        $existingUser = $this->userRepository->findOneBy(['email' => $pendingEmail]);
        if ($existingUser instanceof User && $existingUser->getId() !== $user->getId()) {
            $user->clearPendingEmailChange();
            $this->em->flush();

            return 'unavailable';
        }

        $user
            ->setEmail($pendingEmail)
            ->clearPendingEmailChange();
        $user->invalidateTrustedDevices();

        $this->em->flush();

        return 'confirmed';
    }

    public function cancelEmailChange(User $user): void
    {
        $user->clearPendingEmailChange();

        $this->em->flush();
    }

    public function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function assertEmailCanBeRequested(User $user, string $newEmail): void
    {
        if ($newEmail === $this->normalizeEmail((string) $user->getEmail())) {
            throw new \InvalidArgumentException('Cette adresse email est déjà votre identifiant de connexion.');
        }

        $existingUser = $this->userRepository->findOneBy(['email' => $newEmail]);
        if ($existingUser instanceof User && $existingUser->getId() !== $user->getId()) {
            throw new \InvalidArgumentException('Un compte utilise déjà cette adresse email.');
        }
    }

    private function isConfirmationExpired(User $user): bool
    {
        $requestedAt = $user->getEmailChangeRequestedAt();
        if (null === $requestedAt) {
            return true;
        }

        return new \DateTimeImmutable() > $requestedAt->modify('+' . self::CONFIRMATION_EXPIRATION_HOURS . ' hours');
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
