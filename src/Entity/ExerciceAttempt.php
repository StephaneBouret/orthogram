<?php

namespace App\Entity;

use App\Repository\ExerciceAttemptRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExerciceAttemptRepository::class)]
class ExerciceAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $score = 0;

    #[ORM\Column]
    private int $total = 0;

    #[ORM\Column]
    private int $percentage = 0;

    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $selectedTokenIds = [];

    /**
     * @var list<array<string, mixed>>
     */
    #[ORM\Column]
    private array $correctionItems = [];

    #[ORM\Column]
    private \DateTimeImmutable $submittedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Exercice $exercice = null;

    public function __construct()
    {
        $this->submittedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function setScore(int $score): static
    {
        $this->score = $score;

        return $this;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): static
    {
        $this->total = $total;

        return $this;
    }

    public function getPercentage(): int
    {
        return $this->percentage;
    }

    public function setPercentage(int $percentage): static
    {
        $this->percentage = $percentage;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getSelectedTokenIds(): array
    {
        return $this->selectedTokenIds;
    }

    /**
     * @param list<string> $selectedTokenIds
     */
    public function setSelectedTokenIds(array $selectedTokenIds): static
    {
        $this->selectedTokenIds = array_values($selectedTokenIds);

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCorrectionItems(): array
    {
        return $this->correctionItems;
    }

    /**
     * @param list<array<string, mixed>> $correctionItems
     */
    public function setCorrectionItems(array $correctionItems): static
    {
        $this->correctionItems = array_values($correctionItems);

        return $this;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(\DateTimeImmutable $submittedAt): static
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getExercice(): ?Exercice
    {
        return $this->exercice;
    }

    public function setExercice(?Exercice $exercice): static
    {
        $this->exercice = $exercice;

        return $this;
    }
}
