<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\UtcDateTimeImmutableType;
use App\Services\Dictation\CorrectionContract;
use App\Services\Dictation\DictationAudioStorage;
use App\Services\Dictation\DictationCorrector;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;

/** @phpstan-import-type Audio from DictationAudioStorage */
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'UNIQ_DICTATION_VERSION', fields: ['dictation', 'number'])]
class DictationVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $revision = 1;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Dictation $dictation;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $canonicalText;

    /** @var list<array{referenceIndex: int, alternatives: list<string>}> */
    #[ORM\Column(type: Types::JSON)]
    private array $variants;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $correctionContent;

    #[ORM\Column(length: 64)]
    private string $contractVersion = CorrectionContract::VERSION;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /** @var Audio|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $completeAudio = null;

    /** @var Audio|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $writingAudio = null;

    /** @var list<array{start: int, end: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $segments = [];

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $segmentsAudioKey = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $variantsTextHash = null;

    /** @param list<array{referenceIndex: int, alternatives: list<string>}> $variants */
    public function __construct(Dictation $dictation, int $number, string $title, string $canonicalText, ?string $correctionContent = null, array $variants = [])
    {
        if ($number < 1) {
            throw new \InvalidArgumentException('Numéro de version invalide.');
        }
        $this->dictation = $dictation;
        $this->number = $number;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->edit($title, $canonicalText, $correctionContent, $variants);
        $this->variantsTextHash = hash('sha256', $canonicalText);
    }

    /** @param list<array{referenceIndex: int, alternatives: list<string>}> $variants */
    public function edit(string $title, string $canonicalText, ?string $correctionContent, array $variants = [], bool $variantsReviewed = false): void
    {
        if ($this->isPublished()) {
            throw new \DomainException('Une version publiée est immuable. Créez une nouvelle version.');
        }
        if ('' === trim($title) || mb_strlen($title) > 255) {
            throw new \InvalidArgumentException('Titre invalide.');
        }
        $this->title = $title;
        $this->canonicalText = $canonicalText;
        $this->correctionContent = $correctionContent;
        if (!isset($this->variants) || $this->variants != $variants) {
            $this->variants = $variants;
        }
        if ([] === $variants || $variantsReviewed) {
            $this->variantsTextHash = hash('sha256', $canonicalText);
        }
    }

    /** @param Audio $audio */
    public function replaceAudio(string $role, array $audio): void
    {
        $this->assertDraft();
        if ('complete' === $role) {
            $this->completeAudio = $audio;
        } elseif ('writing' === $role) {
            if ($this->writingAudio !== $audio) {
                $this->writingAudio = $audio;
                $this->segments = [];
                $this->segmentsAudioKey = null;
            }
        } else {
            throw new \InvalidArgumentException('Rôle audio invalide.');
        }
    }

    /** @param array<mixed> $segments */
    public function editSegments(array $segments): void
    {
        $this->assertDraft();
        $segments = $this->validateSegments($segments);
        if ($this->segments != $segments) {
            $this->segments = $segments;
        }
        $this->segmentsAudioKey = [] === $segments ? null : ($this->writingAudio['key'] ?? null);
    }

    /** @param array<mixed> $segments
     * @return list<array{start: int, end: int}>
     */
    private function validateSegments(array $segments): array
    {
        if (count($segments) > 200 || !array_is_list($segments)) {
            throw new \DomainException('200 segments ordonnés au maximum.');
        }
        $previousEnd = 0;
        foreach ($segments as $segment) {
            if (!is_array($segment) || 2 !== count($segment) || !isset($segment['start'], $segment['end'])
                || !is_int($segment['start']) || !is_int($segment['end']) || $segment['start'] < $previousEnd
                || $segment['end'] <= $segment['start'] || $segment['end'] > ($this->writingAudio['durationMs'] ?? 0)) {
                throw new \DomainException('Segments invalides : bornes entières positives en millisecondes, ordre chronologique sans chevauchement, fin dans la durée audio.');
            }
            $previousEnd = $segment['end'];
        }

        return $segments;
    }

    private function assertDraft(): void
    {
        if ($this->isPublished()) {
            throw new \DomainException('Une version publiée est immuable. Créez une nouvelle version.');
        }
    }

    public function publish(DictationCorrector $corrector, DictationAudioStorage $storage): void
    {
        if (CorrectionContract::VERSION !== $this->contractVersion) {
            throw new \DomainException('Contrat de correction non pris en charge.');
        }
        $corrector->correct($this->canonicalText, $this->canonicalText, $this->variants);
        if (!$this->areVariantsReviewed()) {
            throw new \DomainException('Le texte a changé : revérifiez les occurrences ciblées par les variantes.');
        }
        if (null === $this->completeAudio || null === $this->writingAudio) {
            throw new \DomainException('Importez les deux pistes audio avant de publier.');
        }
        $storage->verify($this->completeAudio);
        $storage->verify($this->writingAudio);
        if ([] === $this->segments || $this->segmentsAudioKey !== $this->writingAudio['key']) {
            throw new \DomainException('Validez au moins un segment sur la piste d’écriture actuelle.');
        }
        $this->validateSegments($this->segments);
        if ($this->isPublished()) {
            return;
        }
        $this->publishedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    #[ORM\PreUpdate]
    public function protectPublishedVersion(PreUpdateEventArgs $event): void
    {
        $wasPublished = $event->hasChangedField('publishedAt') ? null !== $event->getOldValue('publishedAt') : $this->isPublished();
        if ($wasPublished) {
            throw new \DomainException('Une version publiée est immuable.');
        }
    }

    #[ORM\PreRemove]
    public function protectDeletion(): void
    {
        if ($this->isPublished()) {
            throw new \DomainException('Une version publiée ne peut pas être supprimée.');
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** @return Audio|null */
    public function getCompleteAudio(): ?array
    {
        return $this->completeAudio;
    }

    /** @return Audio|null */
    public function getWritingAudio(): ?array
    {
        return $this->writingAudio;
    }

    /** @return list<array{start: int, end: int}> */
    public function getSegments(): array
    {
        return $this->segments;
    }

    public function areVariantsReviewed(): bool
    {
        return [] === $this->variants || hash('sha256', $this->canonicalText) === $this->variantsTextHash;
    }

    public function invalidateVariants(): void
    {
        $this->assertDraft();
        $this->variantsTextHash = null;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDictation(): Dictation
    {
        return $this->dictation;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getCanonicalText(): string
    {
        return $this->canonicalText;
    }

    /** @return list<array{referenceIndex: int, alternatives: list<string>}> */
    public function getVariants(): array
    {
        return $this->variants;
    }

    public function getCorrectionContent(): ?string
    {
        return $this->correctionContent;
    }

    public function getContractVersion(): string
    {
        return $this->contractVersion;
    }

    public function isPublished(): bool
    {
        return null !== $this->publishedAt;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }
}
