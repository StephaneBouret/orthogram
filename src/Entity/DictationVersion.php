<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\UtcDateTimeImmutableType;
use App\Services\Dictation\CorrectionContract;
use App\Services\Dictation\DictationCorrector;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;

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
    }

    /** @param list<array{referenceIndex: int, alternatives: list<string>}> $variants */
    public function edit(string $title, string $canonicalText, ?string $correctionContent, array $variants = []): void
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
        $this->variants = $variants;
    }

    // Editorial freeze only. Learner publication still requires the media integration in 2B.
    public function publish(DictationCorrector $corrector): void
    {
        if ($this->isPublished()) {
            return;
        }
        if (CorrectionContract::VERSION !== $this->contractVersion) {
            throw new \DomainException('Contrat de correction non pris en charge.');
        }
        $corrector->correct($this->canonicalText, $this->canonicalText, $this->variants);
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
