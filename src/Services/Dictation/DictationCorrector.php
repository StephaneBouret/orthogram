<?php

declare(strict_types=1);

namespace App\Services\Dictation;

final class DictationCorrector
{
    public function __construct(
        private readonly DictationTokenizer $tokenizer = new DictationTokenizer(),
        private readonly WordAligner $aligner = new WordAligner(),
        private readonly PassageVariants $variants = new PassageVariants(),
        private readonly PunctuationComparator $punctuation = new PunctuationComparator(),
    ) {
    }

    /** @param array<mixed> $variants See PassageVariants for the occurrence-scoped format. */
    public function correct(string $reference, string $copy, array $variants = []): CorrectionResult
    {
        $a = $this->tokenizer->tokenize($reference, CorrectionContract::MAX_REFERENCE_WORDS);
        $b = $this->tokenizer->tokenize($copy);
        if ([] === $a->words) {
            throw new \InvalidArgumentException('La référence doit contenir au moins un mot.');
        }
        $accepted = $this->variants->validate($variants, $a);
        $alignment = $this->aligner->align($a, $b, $accepted);
        $trailingOmissions = $this->punctuation->trailingOmissionPoints($a, $b, $alignment);
        $issues = [];
        foreach ($alignment as $step) {
            if (in_array($step['type'], ['match', 'variant'], true)) {
                continue;
            }
            $start = 'omission' === $step['type'] && isset($trailingOmissions[$step['referenceStart']])
                ? $trailingOmissions[$step['referenceStart']] : $b->insertionPoint($step['copyStart']);
            $end = $step['copyCount'] > 0 ? $b->words[$step['copyStart'] + $step['copyCount'] - 1]['end'] : $start;
            if ('segmentation' === $step['type'] && 1 === $step['copyCount'] && ($b->words[$step['copyStart']]['elision'] ?? false)) {
                // Include whitespace belonging to a misplaced elision boundary, not the following word.
                $end = $b->words[$step['copyStart'] + 1]['start'] ?? $end;
            }
            $issues[] = ['domain' => 'lexical', 'type' => $step['type'], 'start' => $start, 'end' => $end];
        }
        $lexicalCount = count($issues);
        $punctuationIssues = $this->punctuation->compare($a, $b, $alignment);
        array_push($issues, ...$punctuationIssues);
        usort($issues, static fn (array $left, array $right): int => [$left['start'], $left['end'], $left['domain'], $left['type']] <=> [$right['start'], $right['end'], $right['domain'], $right['type']]);

        return new CorrectionResult($reference, $copy, count($a->words), count($b->words), $alignment, $issues, $lexicalCount, count($punctuationIssues));
    }
}
