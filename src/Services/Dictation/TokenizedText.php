<?php

declare(strict_types=1);

namespace App\Services\Dictation;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * @phpstan-type Token array{value: string, start: int, end: int, elision?: bool, spaced?: bool}
 * @phpstan-type Unit array{value: string, spaced: bool, joined: ?bool}
 */
#[Exclude]
final readonly class TokenizedText
{
    /**
     * @param list<Token>  $words
     * @param list<Token>  $punctuation includes all non-word, non-separator characters
     * @param list<string> $gaps        original text between adjacent words, including outer gaps
     */
    public function __construct(
        public string $original,
        public int $length,
        public array $words,
        public array $punctuation,
        public array $gaps,
    ) {
    }

    public function insertionPoint(int $wordIndex): int
    {
        // Neutral trailing anchor: after the last word, before final signs/spaces.
        // Retained reference punctuation can refine it for a lexical omission.
        return $this->words[$wordIndex]['start'] ?? ([] !== $this->words ? $this->words[array_key_last($this->words)]['end'] : 0);
    }

    /** @return list<Unit> Comparison values include the spacing of elided prefixes. */
    public function comparisonUnits(): array
    {
        $units = [];
        foreach ($this->words as $index => $word) {
            $gap = $this->gaps[$index + 1];
            // Punctuation is still evaluated separately; only whitespace breaks a lexical join.
            $joined = ($word['elision'] ?? false) && isset($this->words[$index + 1])
                && 1 === preg_match('~\\A[\\p{Z}\\x{0009}-\\x{000D}]*\\z~u', $gap) ? '' === $gap : null;
            $units[] = ['value' => $word['value'], 'spaced' => $word['spaced'] ?? false, 'joined' => $joined];
        }

        return $units;
    }

    /**
     * @param Unit $a
     * @param Unit $b
     */
    public static function sameSpacing(array $a, array $b): bool
    {
        // A missing final neighbour has no comparable join. Intermediate omissions
        // are evaluated together with their prefix by WordAligner, not waived here.
        return $a['spaced'] === $b['spaced'] && (null === $a['joined'] || null === $b['joined'] || $a['joined'] === $b['joined']);
    }
}
