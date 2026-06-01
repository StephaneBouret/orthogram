<?php

namespace App\Services;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserRegistrationService
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $em,
    ) {}

    public function register(User $user, string $plainPassword): User
    {
        $user
            ->setPassword($this->passwordHasher->hashPassword($user, $plainPassword))
            ->setFirstname($this->normalizeFirstname((string) $user->getFirstname()))
            ->setLastname($this->normalizeLastname((string) $user->getLastname()));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function normalizeFirstname(string $firstname): string
    {
        $firstname = mb_strtolower(trim($firstname), 'UTF-8');

        return mb_convert_case($firstname, MB_CASE_TITLE, 'UTF-8');
    }

    private function normalizeLastname(string $lastname): string
    {
        return mb_strtoupper(trim($lastname), 'UTF-8');
    }
}
