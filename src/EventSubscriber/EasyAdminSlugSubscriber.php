<?php

namespace App\EventSubscriber;

use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityPersistedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityUpdatedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

final class EasyAdminSlugSubscriber implements EventSubscriberInterface
{
    public function __construct(private SluggerInterface $slugger) {}

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeEntityPersistedEvent::class => 'onBeforePersist',
            BeforeEntityUpdatedEvent::class => 'onBeforeUpdate',
        ];
    }

    public function onBeforePersist(BeforeEntityPersistedEvent $event): void
    {
        $entity = $event->getEntityInstance();

        if (!$this->supports($entity)) {
            return;
        }

        $this->normalizeNameAndSlug($entity, true);
    }

    public function onBeforeUpdate(BeforeEntityUpdatedEvent $event): void
    {
        $entity = $event->getEntityInstance();

        if (!$this->supports($entity)) {
            return;
        }

        $this->normalizeNameAndSlug($entity, false);
    }

    private function supports(object $entity): bool
    {
        return method_exists($entity, 'getName')
            && method_exists($entity, 'setName')
            && method_exists($entity, 'getSlug')
            && method_exists($entity, 'setSlug');
    }

    private function normalizeNameAndSlug(object $entity, bool $isNew): void
    {
        $name = trim((string) $entity->getName());

        if ($name == '') {
            return;
        }

        // Normalisation du nom : première lettre en majuscule, reste conservé.
        $normalizedName = $this->capitalizeFirstLetter($name);
        $entity->setName($normalizedName);

        $newSlug = (string) $this->slugger->slug($normalizedName)->lower();
        $currentSlug = (string) ($entity->getSlug() ?? '');

        if ($isNew || $currentSlug === '') {
            $entity->setSlug($newSlug);
            return;
        }

        // À l'édition, on synchronise le slug avec le nom normalisé
        if ($currentSlug !== $newSlug) {
            $entity->setSlug($newSlug);
        }
    }

    private function capitalizeFirstLetter(string $value): string
    {
        $firstLetter = mb_substr($value, 0, 1, 'UTF-8');
        $rest = mb_substr($value, 1, null, 'UTF-8');

        return mb_strtoupper($firstLetter, 'UTF-8') . $rest;
    }
}
