<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\DictationVersion;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsDoctrineListener(event: Events::prePersist)]
final class DictationEnrollmentListener
{
    public function __construct(#[Autowire('%env(bool:DICTATION_LEARNER_ENABLED)%')] private readonly bool $enabled)
    {
    }

    public function prePersist(PrePersistEventArgs $event): void
    {
        $user = $event->getObject();
        if (!$this->enabled || !$user instanceof User || null !== $user->getDictationPrerequisites()) {
            return;
        }
        // Snapshot committed, usable publications. No lesson/bookmark is interpreted as history.
        $versions = $event->getObjectManager()->getRepository(DictationVersion::class)->createQueryBuilder('v')
            ->where('v.publishedAt IS NOT NULL')->getQuery()->getResult();
        $ids = [];
        foreach ($versions as $version) {
            if (null !== $version->getCompleteAudio() && null !== $version->getWritingAudio() && [] !== $version->getSegments()) {
                $ids[] = $version->getDictation()->getId();
            }
        }
        $user->enrollDictationJourney($ids);
    }
}
