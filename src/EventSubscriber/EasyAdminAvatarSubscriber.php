<?php

namespace App\EventSubscriber;

use App\Entity\Avatar;
use App\Entity\User;
use App\Repository\AvatarRepository;
use App\Services\AvatarService;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityPersistedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityUpdatedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class EasyAdminAvatarSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AvatarService $avatarService,
        private readonly AvatarRepository $avatarRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeEntityPersistedEvent::class => 'ensureAvatar',
            BeforeEntityUpdatedEvent::class => 'ensureAvatar',
        ];
    }

    public function ensureAvatar(BeforeEntityPersistedEvent|BeforeEntityUpdatedEvent $event): void
    {
        $entity = $event->getEntityInstance();

        if (!$entity instanceof User) {
            return;
        }

        $avatar = $this->resolveAvatar($entity);

        if (!$avatar instanceof Avatar) {
            $this->avatarService->createAndAssignAvatar($entity);

            return;
        }

        if (null === $avatar->getImageFile() && null === $avatar->getImageName()) {
            $this->avatarService->createDefaultAvatar($avatar, $entity);
        }
    }

    private function resolveAvatar(User $user): ?Avatar
    {
        $avatar = $user->getAvatar();

        if (!$avatar instanceof Avatar || null !== $avatar->getId() || null === $user->getId()) {
            return $avatar;
        }

        $existingAvatar = $this->avatarRepository->findOneBy(['user' => $user]);

        if (!$existingAvatar instanceof Avatar) {
            return $avatar;
        }

        if (null !== $avatar->getImageFile()) {
            $existingAvatar->setImageFile($avatar->getImageFile());
        }

        $user->setAvatar($existingAvatar);

        return $existingAvatar;
    }
}
