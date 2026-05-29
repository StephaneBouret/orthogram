<?php

namespace App\EventSubscriber;

use App\Entity\Company;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityPersistedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityUpdatedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class EasyAdminCompanyWebsiteCreatorSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            BeforeEntityPersistedEvent::class => 'formatCompanyPeopleNames',
            BeforeEntityUpdatedEvent::class => 'formatCompanyPeopleNames',
        ];
    }

    public function formatCompanyPeopleNames(BeforeEntityPersistedEvent|BeforeEntityUpdatedEvent $event): void
    {
        $entity = $event->getEntityInstance();

        if (!$entity instanceof Company) {
            return;
        }

        $entity->setManager($this->formatFirstnameLastname($entity->getManager()));
        $entity->setWebsiteCreator($this->formatFirstnameLastname($entity->getWebsiteCreator()));
    }

    private function formatFirstnameLastname(?string $value): ?string
    {
        $value = trim((string) $value);

        if ('' === $value) {
            return null;
        }

        $parts = preg_split('/\s+/', $value, 2);

        if (false === $parts || [] === $parts) {
            return null;
        }

        $firstname = $this->formatFirstname($parts[0]);
        $lastname = isset($parts[1]) ? mb_strtoupper(trim($parts[1]), 'UTF-8') : '';

        return trim($firstname.' '.$lastname);
    }

    private function formatFirstname(string $firstname): string
    {
        return preg_replace_callback(
            '/(^|[-\'])(\p{L})/u',
            static fn (array $matches): string => $matches[1].mb_strtoupper($matches[2], 'UTF-8'),
            mb_strtolower($firstname, 'UTF-8')
        ) ?? $firstname;
    }
}
