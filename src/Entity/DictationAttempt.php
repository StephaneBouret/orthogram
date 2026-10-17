<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\UtcDateTimeImmutableType;
use App\Enum\DictationAction;
use App\Enum\DictationState;
use App\Services\Dictation\CorrectionContract;
use App\Services\Dictation\DictationCorrector;
use App\Services\Dictation\DictationTokenizer;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Internal aggregate. Never serialize this entity into a learner response. */
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'UNIQ_DICTATION_ATTEMPT', fields: ['user', 'dictation'])]
class DictationAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Dictation $dictation;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private DictationVersion $version;

    #[ORM\Column(length: 32, enumType: DictationState::class)]
    private DictationState $state = DictationState::Writing;

    #[ORM\Column]
    private int $revision = 0;

    #[ORM\Column(type: Types::TEXT)]
    private string $draft = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $initialCopy = null;

    /** @var array<string, mixed>|null First public feedback, including original UTF-16 issues. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $initialResult = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $correctedCopy = null;

    /** @var array<string, mixed>|null Summary only, no alignment or issues. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $correctedResult = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $finalCopy = null;

    /** @var array<string, mixed>|null Summary only. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $finalResult = null;

    /** @var array<string, string> Fixed transition receipts and only the latest save; no keystroke history. */
    #[ORM\Column(type: Types::JSON)]
    private array $receipts = [];

    #[ORM\Column]
    private bool $latePassage;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $initialEvaluatedAt = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $resumedAt = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $finalizedAt = null;

    public function __construct(User $user, DictationVersion $version, bool $sectionStarted)
    {
        if (!$version->isPublished()) {
            throw new \DomainException('La tentative exige une version éditoriale figée.');
        }
        $this->user = $user;
        $this->version = $version;
        $this->dictation = $version->getDictation();
        $this->latePassage = $sectionStarted;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** Only called with server-derived completion under the persistence service's transaction. */
    public function transition(DictationAction $action, int $expectedRevision, ?string $copy, bool $coursesComplete, bool $sectionStarted, DictationCorrector $engine, DictationTokenizer $tokenizer): void
    {
        // An old receipt never grants access after a course validation was revoked.
        if (in_array($action, [DictationAction::Resume, DictationAction::Finalize], true)) {
            $this->requireCourses($coursesComplete);
        }
        $receipt = hash('sha256', json_encode([$action->value, $expectedRevision, $copy], JSON_THROW_ON_ERROR));
        if (($this->receipts[$action->value] ?? null) === $receipt) {
            return;
        }
        if ($expectedRevision !== $this->revision || (DictationAction::Save !== $action && isset($this->receipts[$action->value]))) {
            throw new ConflictHttpException('Cette demande est périmée ou contredit une étape figée.');
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        switch ($action) {
            case DictationAction::Save:
                $this->requireState(DictationState::Writing, DictationState::SelfCorrection, DictationState::Resuming);
                $this->validateCopy($copy, $tokenizer, false);
                $this->draft = $copy;
                break;
            case DictationAction::Evaluate:
                $this->requireState(DictationState::Writing);
                $feedback = $this->evaluate($copy, $engine, $tokenizer);
                $this->initialCopy = $this->draft = $copy;
                $this->initialResult = $feedback;
                $this->initialEvaluatedAt = $now;
                $this->state = $feedback['noErrors'] ? DictationState::AwaitingCourses : DictationState::SelfCorrection;
                if ($feedback['noErrors']) {
                    $this->closedAt = $now;
                }
                break;
            case DictationAction::Close:
                $this->requireState(DictationState::SelfCorrection);
                $feedback = $this->evaluate($copy, $engine, $tokenizer);
                unset($feedback['issues']);
                $this->correctedCopy = $this->draft = $copy;
                $this->correctedResult = $feedback;
                $this->closedAt = $now;
                $this->state = DictationState::AwaitingCourses;
                break;
            case DictationAction::Resume:
                $this->requireState(DictationState::AwaitingCourses);
                $this->requireCourses($coursesComplete);
                if (null !== $copy) {
                    throw new \InvalidArgumentException('La reprise utilise la copie clôturée.');
                }
                $this->draft = $this->getClosedCopy() ?? throw new \LogicException('Copie clôturée absente.');
                $this->state = $this->closedWithoutErrors() ? DictationState::Reviewing : DictationState::Resuming;
                $this->resumedAt = $now;
                break;
            case DictationAction::Finalize:
                $this->requireState(DictationState::Resuming, DictationState::Reviewing);
                $this->requireCourses($coursesComplete);
                if (DictationState::Reviewing === $this->state) {
                    if (null !== $copy) {
                        throw new \InvalidArgumentException('Le bilan réutilise le résultat sans erreur.');
                    }
                } else {
                    $feedback = $this->evaluate($copy, $engine, $tokenizer);
                    unset($feedback['issues']);
                    $this->finalCopy = $this->draft = $copy;
                    $this->finalResult = $feedback;
                }
                $this->finalizedAt = $now;
                $this->state = DictationState::Completed;
                break;
        }
        // Detection stays open through initial closure, never through the normal course phase.
        if (null === $this->resumedAt && null === $this->finalizedAt && $sectionStarted) {
            $this->latePassage = true;
        }
        // A save is replayable only until the next accepted mutation, including finalization.
        unset($this->receipts[DictationAction::Save->value]);
        $this->receipts[$action->value] = $receipt;
        ++$this->revision;
        $this->updatedAt = $now;
    }

    private function requireState(DictationState ...$states): void
    {
        if (!in_array($this->state, $states, true)) {
            throw new ConflictHttpException('Transition interdite dans cet état.');
        }
    }

    private function requireCourses(bool $complete): void
    {
        if (!$complete) {
            throw new ConflictHttpException('Validez tous les cours de la section avant cette transition.');
        }
    }

    /** @phpstan-assert string $copy */
    private function validateCopy(?string $copy, DictationTokenizer $tokenizer, bool $nonEmpty): void
    {
        if (null === $copy || ($nonEmpty && 1 === preg_match('~\A[\p{Z}\x{0009}-\x{000D}]*\z~u', $copy))) {
            throw new \InvalidArgumentException('La copie à évaluer ne peut pas être vide.');
        }
        $tokenizer->tokenize($copy);
    }

    /** @return array<string, mixed>
     * @phpstan-assert string $copy
     */
    private function evaluate(?string $copy, DictationCorrector $engine, DictationTokenizer $tokenizer): array
    {
        $this->validateCopy($copy, $tokenizer, true);
        if (CorrectionContract::VERSION !== $this->version->getContractVersion()) {
            throw new \DomainException('Contrat de correction non pris en charge.');
        }

        return $engine->correct($this->version->getCanonicalText(), $copy, $this->version->getVariants())->publicFeedback();
    }

    #[ORM\PreUpdate]
    public function protectFrozenData(PreUpdateEventArgs $event): void
    {
        foreach (['user', 'dictation', 'version', 'initialCopy', 'initialResult', 'initialEvaluatedAt', 'correctedCopy', 'correctedResult', 'closedAt', 'finalCopy', 'finalResult', 'finalizedAt'] as $field) {
            if ($event->hasChangedField($field) && null !== $event->getOldValue($field)) {
                throw new \DomainException('Les données figées de la tentative sont immuables.');
            }
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDictation(): Dictation
    {
        return $this->dictation;
    }

    public function getVersion(): DictationVersion
    {
        return $this->version;
    }

    public function getState(): DictationState
    {
        return $this->state;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getDraft(): string
    {
        return $this->draft;
    }

    public function getInitialCopy(): ?string
    {
        return $this->initialCopy;
    }

    public function getClosedCopy(): ?string
    {
        return $this->correctedCopy ?? $this->initialCopy;
    }

    public function getFinalCopy(): ?string
    {
        return $this->finalCopy;
    }

    public function isLatePassage(): bool
    {
        return $this->latePassage;
    }

    public function isPrerequisiteSatisfied(): bool
    {
        return null !== $this->closedAt;
    }

    public function closedWithoutErrors(): bool
    {
        return true === ($this->getClosedResult()['noErrors'] ?? false);
    }

    /** @return array<string, mixed>|null */
    public function getInitialResult(): ?array
    {
        return $this->normalizeScore($this->initialResult);
    }

    /** @return array<string, mixed>|null */
    public function getClosedResult(): ?array
    {
        return $this->normalizeScore($this->correctedResult ?? $this->initialResult);
    }

    /** @return array<string, mixed>|null */
    public function getFinalResult(): ?array
    {
        return $this->normalizeScore($this->finalResult);
    }

    /** @param array<string, mixed>|null $result
     * @return array<string, mixed>|null
     */
    private function normalizeScore(?array $result): ?array
    {
        if (null !== $result) {
            $result['score'] = (float) $result['score'];
            // Native MySQL JSON may reorder object keys; keep the public projection stable.
            $ordered = [];
            foreach (['contractVersion', 'referenceWordCount', 'copyWordCount', 'lexicalErrorCount', 'punctuationErrorCount', 'score', 'noErrors', 'issues'] as $key) {
                if (array_key_exists($key, $result)) {
                    $ordered[$key] = $result[$key];
                }
            }
            if (isset($ordered['issues'])) {
                $ordered['issues'] = array_map(static fn (array $issue): array => [
                    'domain' => $issue['domain'], 'type' => $issue['type'], 'start' => $issue['start'], 'end' => $issue['end'],
                ], $ordered['issues']);
            }

            return $ordered;
        }

        return $result;
    }

    /** @return array<string, ?string> */
    public function dates(): array
    {
        return ['createdAt' => $this->createdAt->format(DATE_ATOM), 'updatedAt' => $this->updatedAt->format(DATE_ATOM),
            'initialEvaluatedAt' => $this->initialEvaluatedAt?->format(DATE_ATOM), 'closedAt' => $this->closedAt?->format(DATE_ATOM),
            'resumedAt' => $this->resumedAt?->format(DATE_ATOM), 'finalizedAt' => $this->finalizedAt?->format(DATE_ATOM)];
    }
}
