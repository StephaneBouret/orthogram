<?php

declare(strict_types=1);

namespace App\Services\Dictation;

use App\Entity\Dictation;
use App\Entity\DictationVersion;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Internal editorial operations. Authorization/UI/media readiness belong to the future integration. */
final class DictationEditorialService
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly DictationCorrector $corrector)
    {
    }

    /** @param list<array{referenceIndex: int, alternatives: list<string>}> $variants */
    public function createVersion(Dictation $dictation, string $title, string $text, ?string $correction = null, array $variants = []): DictationVersion
    {
        return $this->em->wrapInTransaction(function () use ($dictation, $title, $text, $correction, $variants): DictationVersion {
            $this->em->refresh($dictation, LockMode::PESSIMISTIC_WRITE);
            $number = 1 + (int) $this->em->createQueryBuilder()->select('MAX(v.number)')->from(DictationVersion::class, 'v')
                ->where('v.dictation = :dictation')->setParameter('dictation', $dictation)->getQuery()->getSingleScalarResult();
            $version = new DictationVersion($dictation, $number, $title, $text, $correction, $variants);
            $this->em->persist($version);

            return $version;
        });
    }

    public function saveDraft(DictationVersion $version, int $expectedRevision): void
    {
        $this->requireManagedVersion($version);
        $this->em->wrapInTransaction(function () use ($version, $expectedRevision): void {
            $this->lockRevision($version, $expectedRevision);
            if ($version->isPublished()) {
                throw new ConflictHttpException('Une version publiée est immuable. Créez une nouvelle version.');
            }
            // wrapInTransaction flushes the edited draft only after both locks and revision checks.
        });
    }

    public function publish(DictationVersion $version, int $expectedRevision): void
    {
        $this->requireManagedVersion($version);
        // Check current fields, not a possibly uncomputed/stale UnitOfWork change set.
        // Do this before any refresh/transaction: an unsaved form keeps its submitted content.
        $unitOfWork = $this->em->getUnitOfWork();
        if ([] !== $unitOfWork->getEntityChangeSet($version)) {
            throw new ConflictHttpException('Modifications non enregistrées. Enregistrez le brouillon avant de le publier.');
        }
        $original = $unitOfWork->getOriginalEntityData($version);
        $metadata = $this->em->getClassMetadata(DictationVersion::class);
        foreach ($metadata->getFieldNames() as $field) {
            // Generated IDs/versions are not reliably present in flush snapshots.
            // The managed ID and SQL revision are checked separately.
            if ($metadata->isIdentifier($field) || $field === $metadata->versionField) {
                continue;
            }
            if (!array_key_exists($field, $original) || $metadata->getFieldValue($version, $field) !== $original[$field]) {
                throw new ConflictHttpException('Modifications non enregistrées. Enregistrez le brouillon avant de le publier.');
            }
        }
        $this->em->wrapInTransaction(function () use ($version, $expectedRevision): void {
            $this->lockRevision($version, $expectedRevision);
            $version->publish($this->corrector);
        });
    }

    private function requireManagedVersion(DictationVersion $version): void
    {
        if (null === $version->getId() || !$this->em->contains($version)) {
            throw new ConflictHttpException('Une version enregistrée et gérée est requise.');
        }
    }

    private function lockRevision(DictationVersion $version, int $expectedRevision): void
    {
        // Same lock order as start/version creation, without rehydrating the submitted entity.
        $this->em->lock($version->getDictation(), LockMode::PESSIMISTIC_WRITE);
        $revision = $this->em->getConnection()->fetchOne(
            'SELECT revision FROM dictation_version WHERE id = ? FOR UPDATE', [$version->getId()]
        );
        if (false === $revision || $expectedRevision !== $version->getRevision() || $expectedRevision !== (int) $revision) {
            throw new ConflictHttpException('La version a changé. Comparez les modifications avec la version enregistrée avant de réessayer.');
        }
    }
}
