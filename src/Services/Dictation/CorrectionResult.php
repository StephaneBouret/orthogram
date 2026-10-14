<?php

declare(strict_types=1);

namespace App\Services\Dictation;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Internal value object: never pass this object to a serializer or a template.
 * Use publicFeedback(), an explicit allowlist that contains no editorial content.
 * lexicalErrorCount includes agreement and other grammatical differences detected by comparison;
 * the future UI label is "erreurs d’orthographe", without automatic grammar classification.
 *
 * @phpstan-import-type Step from WordAligner
 * @phpstan-import-type Issue from PunctuationComparator
 *
 * @phpstan-type Feedback array{contractVersion: string, referenceWordCount: int, copyWordCount: int, lexicalErrorCount: int, punctuationErrorCount: int, score: float, noErrors: bool, issues: list<Issue>}
 */
#[Exclude]
final readonly class CorrectionResult
{
    /**
     * @param list<Step>  $alignment
     * @param list<Issue> $issues
     */
    public function __construct(
        public string $referenceOriginal,
        public string $copyOriginal,
        public int $referenceWordCount,
        public int $copyWordCount,
        public array $alignment,
        private array $issues,
        public int $lexicalErrorCount,
        public int $punctuationErrorCount,
    ) {
    }

    public function noErrors(): bool
    {
        return 0 === $this->lexicalErrorCount && 0 === $this->punctuationErrorCount;
    }

    public function score(): float
    {
        return round(100 * max(0, 1 - $this->lexicalErrorCount / $this->referenceWordCount), 1);
    }

    /** @return Feedback */
    public function publicFeedback(): array
    {
        return ['contractVersion' => CorrectionContract::VERSION,
            'referenceWordCount' => $this->referenceWordCount, 'copyWordCount' => $this->copyWordCount,
            'lexicalErrorCount' => $this->lexicalErrorCount, 'punctuationErrorCount' => $this->punctuationErrorCount,
            'score' => $this->score(), 'noErrors' => $this->noErrors(), 'issues' => $this->issues];
    }
}
