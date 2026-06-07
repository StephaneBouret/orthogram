<?php

namespace App\Services;

use App\Entity\User;
use App\Enum\SubscriptionStatus;
use App\Enum\UserAccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserProfileService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AvatarService $avatarService,
    ) {}

    public function updateProfile(User $user): void
    {
        $user
            ->setFirstname($this->normalizeFirstname((string) $user->getFirstname()))
            ->setLastname($this->normalizeLastname((string) $user->getLastname()))
            ->setAddress($this->normalizeAddress((string) $user->getAddress()))
            ->setPostalCode(trim((string) $user->getPostalCode()))
            ->setCity($this->normalizeFirstname((string) $user->getCity()));

        $this->em->flush();
    }

    public function updatePassword(User $user, string $plainPassword): void
    {
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        $this->em->flush();
    }

    public function deleteAccount(User $user): void
    {
        $this->anonymizeAccount($user);

        $this->em->flush();
    }

    private function anonymizeAccount(User $user): void
    {
        if ($user->isAnonymized()) {
            return;
        }

        $now = new \DateTimeImmutable();
        $anonymousEmail = $this->createAnonymousEmail($user);

        $user
            ->setFirstname('Utilisateur')
            ->setLastname('Supprime')
            ->setEmail($anonymousEmail)
            ->setPhone(PhoneNumberUtil::getInstance()->parse('+33100000000', 'FR'))
            ->setAddress('Adresse supprimee')
            ->setPostalCode('75001')
            ->setCity('Ville Supprimee')
            ->setRoles(['ROLE_USER'])
            ->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))))
            ->setResetToken(null)
            ->setResetTokenCreatedAt(null)
            ->setAccountStatus(UserAccountStatus::DELETED)
            ->setDeletedAt($now)
            ->setAnonymizedAt($now);

        foreach ($user->getSubscriptions() as $subscription) {
            $subscription->setEmail($anonymousEmail);

            if (in_array($subscription->getStatus(), [
                SubscriptionStatus::PENDING,
                SubscriptionStatus::ACTIVE,
                SubscriptionStatus::SUSPENDED,
            ], true)) {
                $subscription->cancel();
            }
        }

        $invitation = $user->getInvitation();
        if ($invitation !== null) {
            $invitation
                ->setEmail($this->createAnonymousInvitationEmail($user))
                ->setToken(bin2hex(random_bytes(32)))
                ->setExpiresAt($now);
        }

        $avatar = $user->getAvatar();
        if ($avatar !== null) {
            $this->avatarService->deleteAvatar($avatar);
        }
    }

    private function createAnonymousEmail(User $user): string
    {
        return sprintf('deleted-user-%s@example.invalid', $user->getId() ?? bin2hex(random_bytes(6)));
    }

    private function createAnonymousInvitationEmail(User $user): string
    {
        return sprintf('deleted-invitation-user-%s@example.invalid', $user->getId() ?? bin2hex(random_bytes(6)));
    }

    private function normalizeFirstname(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    private function normalizeLastname(string $lastname): string
    {
        return mb_strtoupper(trim($lastname), 'UTF-8');
    }

    private function normalizeText(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function normalizeAddress(string $address): string
    {
        $address = mb_strtolower($this->normalizeText($address), 'UTF-8');

        return preg_replace_callback(
            '/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u',
            fn (array $matches): string => $this->normalizeAddressWord($matches[0]),
            $address
        ) ?? $address;
    }

    private function normalizeAddressWord(string $word): string
    {
        if (preg_match('/^\d+$/', $word) === 1) {
            return $word;
        }

        if (in_array($word, [
            'allée',
            'avenue',
            'bis',
            'boulevard',
            'chemin',
            'cours',
            'de',
            'des',
            'du',
            'impasse',
            'la',
            'le',
            'les',
            'lieu-dit',
            'place',
            'quai',
            'route',
            'rue',
            'square',
            'ter',
            'quater',
            'villa',
        ], true)) {
            return $word;
        }

        if (preg_match('/^([dl])([\'’])(.+)$/u', $word, $matches) === 1) {
            return $matches[1] . $matches[2] . $this->titleAddressName($matches[3]);
        }

        return $this->titleAddressName($word);
    }

    private function titleAddressName(string $word): string
    {
        if (str_contains($word, '-')) {
            return implode('-', array_map(
                fn (string $part): string => $this->titleAddressName($part),
                explode('-', $word)
            ));
        }

        return mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
    }
}
