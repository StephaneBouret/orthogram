<?php

namespace App\EventSubscriber;

use App\Entity\Avatar;
use App\Entity\User;
use App\Services\AvatarService;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityPersistedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityUpdatedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class EasyAdminAvatarSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AvatarService $avatarService) {}

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

        $avatar = $entity->getAvatar();

        if (!$avatar instanceof Avatar) {
            $this->avatarService->createAndAssignAvatar($entity);

            return;
        }

        if ($avatar->getImageFile() === null && $avatar->getImageName() === null) {
            $this->avatarService->createDefaultAvatar($avatar, $entity);
        }
    }
}
