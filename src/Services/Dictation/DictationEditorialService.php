<?php

declare(strict_types=1);

namespace App\Services\Dictation;

use App\Entity\Dictation;
use App\Entity\DictationVersion;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Editorial transactions. All version changes share the same revision and lock order. */
final class DictationEditorialService
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly DictationCorrector $corrector, private readonly DictationAudioStorage $storage)
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
        $this->requireSavedVersion($version);
        $this->em->wrapInTransaction(function () use ($version, $expectedRevision): void {
            $this->lockRevision($version, $expectedRevision);
            $version->publish($this->corrector, $this->storage);
        });
    }

    public function deleteDraft(int $dictationId, int $versionId, int $expectedRevision): void
    {
        $this->em->wrapInTransaction(function () use ($dictationId, $versionId, $expectedRevision): void {
            $stored = $this->lockStoredVersion($dictationId, $versionId, $expectedRevision);
            if (null !== $stored['published_at']) {
                throw new ConflictHttpException('Une version publiée ne peut jamais être supprimée.');
            }
            // Current locking read, even if this transaction already has a REPEATABLE READ snapshot.
            // The version lock also blocks concurrent FK references until the deletion commits.
            if (false !== $this->em->getConnection()->fetchOne('SELECT id FROM dictation_attempt WHERE version_id = ? LIMIT 1 FOR UPDATE', [$versionId])) {
                throw new ConflictHttpException('Cette version est liée à une tentative et ne peut pas être supprimée.');
            }
            $version = $this->em->find(DictationVersion::class, $versionId) ?? throw new NotFoundHttpException('Cette version a déjà été supprimée ou est introuvable.');
            $this->em->remove($version);
            // Only the version row is removed. Shared physical audio files are deliberately retained.
        });
    }

    private function requireSavedVersion(DictationVersion $version): void
    {
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
    }

    public function cloneVersion(DictationVersion $source, int $expectedRevision): DictationVersion
    {
        $this->requireManagedVersion($source);
        $this->requireSavedVersion($source);

        return $this->em->wrapInTransaction(function () use ($source, $expectedRevision): DictationVersion {
            $this->lockRevision($source, $expectedRevision);
            $copy = $this->createVersion($source->getDictation(), $source->getTitle(), $source->getCanonicalText(), $source->getCorrectionContent(), $source->getVariants());
            if (!$source->areVariantsReviewed()) {
                // Cloning must not silently validate obsolete occurrence indexes.
                $copy->invalidateVariants();
            }
            foreach (['complete' => $source->getCompleteAudio(), 'writing' => $source->getWritingAudio()] as $role => $audio) {
                if (null !== $audio) {
                    $this->storage->verify($audio);
                    $copy->replaceAudio($role, $audio);
                }
            }
            $copy->editSegments($source->getSegments());

            return $copy;
        });
    }

    public function importAudio(DictationVersion $version, int $expectedRevision, string $role, \Symfony\Component\HttpFoundation\File\UploadedFile $file): void
    {
        $this->requireManagedVersion($version);
        $this->requireSavedVersion($version);
        $audio = $this->storage->import($file);
        try {
            $this->em->wrapInTransaction(function () use ($version, $expectedRevision, $role, $audio): void {
                $this->lockRevision($version, $expectedRevision);
                $version->replaceAudio($role, $audio);
            });
        } catch (\Throwable $error) {
            // A commit acknowledgement can fail after the database committed. Delete only
            // when a subsequent read proves this unique new key has no persisted owner.
            // If the database is unreachable, retain the possible orphan for manual review.
            try {
                $references = $this->em->getConnection()->fetchOne(
                    "SELECT COUNT(*) FROM dictation_version WHERE JSON_UNQUOTE(JSON_EXTRACT(complete_audio, '$.key')) = ? OR JSON_UNQUOTE(JSON_EXTRACT(writing_audio, '$.key')) = ?",
                    [$audio['key'], $audio['key']]
                );
                if (0 === (int) $references) {
                    $this->storage->discardNewImport($audio);
                }
            } catch (\Doctrine\DBAL\Exception) {
                // No global purge, and no deletion when SQL outcome is uncertain.
            }
            throw $error;
        }
    }

    private function requireManagedVersion(DictationVersion $version): void
    {
        if (null === $version->getId() || !$this->em->contains($version)) {
            throw new ConflictHttpException('Une version enregistrée et gérée est requise.');
        }
    }

    private function lockRevision(DictationVersion $version, int $expectedRevision): void
    {
        $this->lockStoredVersion($version->getDictation()->getId(), $version->getId(), $expectedRevision);
        if ($expectedRevision !== $version->getRevision()) {
            throw new ConflictHttpException('La version a changé. Comparez les modifications avec la version enregistrée avant de réessayer.');
        }
    }

    /** @return array<string, mixed> */
    private function lockStoredVersion(?int $dictationId, ?int $versionId, int $expectedRevision): array
    {
        // Same lock order as start/version creation, without rehydrating submitted fields.
        $connection = $this->em->getConnection();
        if (false === $connection->fetchOne('SELECT id FROM dictation WHERE id = ? FOR UPDATE', [$dictationId])) {
            throw new NotFoundHttpException('Dictée introuvable.');
        }
        $stored = $connection->fetchAssociative('SELECT dictation_id, revision, published_at FROM dictation_version WHERE id = ? FOR UPDATE', [$versionId]);
        if (false === $stored || (int) $stored['dictation_id'] !== $dictationId) {
            throw new NotFoundHttpException('Cette version a déjà été supprimée ou est introuvable dans cette dictée.');
        }
        if ($expectedRevision < 1 || $expectedRevision !== (int) $stored['revision']) {
            throw new ConflictHttpException('La version a changé. Comparez les modifications avec la version enregistrée avant de réessayer.');
        }

        return $stored;
    }
}
