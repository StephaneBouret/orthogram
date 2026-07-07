<?php

namespace App\Services;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\TokenGenerator\TokenGeneratorInterface;

class PasswordResetService
{
    private const RESET_TOKEN_EXPIRATION_HOURS = 3;

    public function __construct(
        protected TokenGeneratorInterface $tokenGenerator,
        protected EntityManagerInterface $em,
        protected UrlGeneratorInterface $urlGenerator,
        protected SendMailService $email,
        protected UserRepository $userRepository,
        protected UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function processPasswordReset(User $user): void
    {
        $token = $this->tokenGenerator->generateToken();
        $now = new \DateTimeImmutable();

        $user->setResetToken($this->hashToken($token))
            ->setResetTokenCreatedAt($now);

        $this->em->persist($user);
        $this->em->flush();

        $url = $this->urlGenerator->generate('app_reset_pw', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);

        $context = [
            'url' => $url,
            'user' => $user,
        ];

        $this->email->sendMail(
            'Infos de l\'application Sym Numeo',
            $user->getEmail(),
            'Réinitialisation de mot de passe',
            'password_reset',
            $context,
            null
        );
    }

    public function getUserByResetToken(string $token): ?User
    {
        $token = trim($token);

        if ('' === $token) {
            return null;
        }

        return $this->userRepository->findOneBy(['resetToken' => $this->hashToken($token)]);
    }

    public function isTokenExpired(User $user, int $expirationInHours = self::RESET_TOKEN_EXPIRATION_HOURS): bool
    {
        $resetTokenAt = $user->getResetTokenCreatedAt();
        $now = new \DateTimeImmutable();

        if (null === $resetTokenAt) {
            return true;
        }

        return $now > $resetTokenAt->modify("+{$expirationInHours} hour");
    }

    public function updatePassword(User $user, string $plainPassword): void
    {
        $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->setPassword($hashedPassword);
        $user->invalidateTrustedDevices();

        $user->setResetToken(null)
            ->setResetTokenCreatedAt(null);

        $this->em->flush();
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
