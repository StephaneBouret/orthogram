<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Snapshot schema v1: editorial IDs are values, never foreign keys. No public serialization.
 *
 * @phpstan-type FrozenAnswer array{id: int, content: string, correct: bool}
 * @phpstan-type FrozenQuestion array{id: int, title: string, text: ?string, multiple: bool, theme: ?string, explanation: string, answers: list<FrozenAnswer>}
 * @phpstan-type Snapshot array{version: int, title: string, questions: list<FrozenQuestion>}
 * @phpstan-type AnswerRecord array{selectedIds: list<int>, correct: bool, validatedAt: string}
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'UNIQ_QUIZ_ACTIVE', fields: ['user', 'course', 'quiz', 'activeSlot'])]
#[ORM\UniqueConstraint(name: 'UNIQ_QUIZ_RESTART', fields: ['previousAttempt'])]
class QuizAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Courses $course;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Quiz $quiz;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $previousAttempt;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    // MySQL permits multiple NULLs in a unique key, but only one active value (1).
    #[ORM\Column(nullable: true)]
    private ?int $activeSlot = 1;

    #[ORM\Column]
    private int $total;

    #[ORM\Column]
    private int $score = 0;

    /** @var Snapshot */
    #[ORM\Column(type: Types::JSON)]
    private array $snapshot;

    /** @var list<AnswerRecord> Append-only, in snapshot question order. */
    #[ORM\Column(type: Types::JSON)]
    private array $responses = [];

    /** @param Snapshot $snapshot */
    public function __construct(User $user, Courses $course, Quiz $quiz, array $snapshot, ?self $previousAttempt = null)
    {
        $this->user = $user;
        $this->course = $course;
        $this->quiz = $quiz;
        $this->snapshot = $snapshot;
        $this->total = count($snapshot['questions']);
        $this->previousAttempt = $previousAttempt;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCourse(): ?Courses
    {
        return $this->course;
    }

    public function getQuiz(): ?Quiz
    {
        return $this->quiz;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function getPreviousAttempt(): ?self
    {
        return $this->previousAttempt;
    }

    public function isInProgress(): bool
    {
        return 1 === $this->activeSlot;
    }

    /** @return Snapshot */
    public function getSnapshot(): array
    {
        return $this->snapshot;
    }

    /** @return list<AnswerRecord> */
    public function getResponses(): array
    {
        return $this->responses;
    }

    /** @param list<int> $selectedIds */
    public function record(array $selectedIds, bool $correct): void
    {
        if (null !== $this->completedAt || count($this->responses) >= $this->total) {
            throw new \LogicException('Tentative déjà renseignée.');
        }
        $this->responses[] = ['selectedIds' => $selectedIds, 'correct' => $correct, 'validatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM)];
        $this->score += $correct ? 1 : 0;
    }

    public function complete(): void
    {
        if (count($this->responses) !== $this->total) {
            throw new \LogicException('Tentative incomplète.');
        }
        $this->completedAt ??= new \DateTimeImmutable();
        $this->activeSlot = null;
    }
}
