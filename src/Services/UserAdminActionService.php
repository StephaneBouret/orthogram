<?php

namespace App\Services;

use App\Entity\User;
use App\Enum\UserAccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class UserAdminActionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SendMailService $sendMailService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {}

    public function suspend(User $user): bool
    {
        $this->assertStatusAllowed(
            $user,
            [UserAccountStatus::ACTIVE],
            'Seuls les comptes actifs peuvent être suspendus.'
        );

        $user->setAccountStatus(UserAccountStatus::SUSPENDED);

        return $this->flushAndNotify(
            $user,
            'Votre compte Orthogram est suspendu',
            'Compte suspendu',
            'Votre compte Orthogram vient d\'être suspendu. Si vous pensez qu\'il s\'agit d\'une erreur, vous pouvez contacter le support.',
            'Découvrir Orthogram',
            $this->urlGenerator->generate('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL)
        );
    }

    public function reactivate(User $user): bool
    {
        $this->assertStatusAllowed(
            $user,
            [UserAccountStatus::SUSPENDED],
            'Seuls les comptes suspendus peuvent être réactivés.'
        );

        $user->setAccountStatus(UserAccountStatus::ACTIVE);

        return $this->flushAndNotify(
            $user,
            'Votre compte Orthogram est réactivé',
            'Compte réactivé',
            'Votre compte Orthogram vient d\'être réactivé. Vous pouvez de nouveau accéder à votre espace.',
            'Me connecter',
            $this->urlGenerator->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL)
        );
    }

    /**
     * @param UserAccountStatus[] $allowedStatuses
     */
    private function assertStatusAllowed(User $user, array $allowedStatuses, string $message): void
    {
        if (!in_array($user->getAccountStatus(), $allowedStatuses, true)) {
            throw new \RuntimeException($message);
        }
    }

    private function flushAndNotify(
        User $user,
        string $subject,
        string $title,
        string $message,
        string $actionLabel,
        string $actionUrl
    ): bool {
        $this->em->flush();

        try {
            $this->sendMailService->sendMail(
                'Orthogram',
                (string) $user->getEmail(),
                $subject,
                'user_admin_action',
                [
                    'user' => $user,
                    'title' => $title,
                    'message' => $message,
                    'actionLabel' => $actionLabel,
                    'actionUrl' => $actionUrl,
                ],
                null
            );
        } catch (\Throwable $e) {
            $this->logger->error('Erreur lors de l\'envoi de l\'email d\'action admin utilisateur.', [
                'userId' => $user->getId(),
                'email' => $user->getEmail(),
                'exception' => $e,
            ]);

            return false;
        }

        return true;
    }
}
