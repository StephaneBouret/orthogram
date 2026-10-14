<?php

declare(strict_types=1);

namespace App\Services\Dictation;

/**
 * Punctuation is compared in gaps anchored to the lexical alignment, never by sentence number.
 * A divergent gap (after trimming equal prefix/suffix) counts as one punctuation zone.
 * This linear algorithm also bounds the cost of punctuation-only, 20,000-unit inputs.
 *
 * @phpstan-import-type Token from TokenizedText
 * @phpstan-import-type Step from WordAligner
 *
 * @phpstan-type Issue array{domain: string, type: string, start: int, end: int}
 */
final class PunctuationComparator
{
    /**
     * @param list<Step> $steps
     *
     * @return list<Issue>
     */
    public function compare(TokenizedText $reference, TokenizedText $copy, array $steps): array
    {
        $issues = [];
        $aCursor = $bCursor = 0;
        $previousCopyEnd = null;
        foreach ($steps as $step) {
            if (0 === $step['referenceCount'] || 0 === $step['copyCount']) {
                continue;
            }
            $aStart = $reference->words[$step['referenceStart']]['start'];
            $bStart = $copy->words[$step['copyStart']]['start'];
            $this->appendGap($issues,
                $this->take($reference->punctuation, $aCursor, $aStart),
                $this->take($copy->punctuation, $bCursor, $bStart), $previousCopyEnd ?? $bStart);

            $aEnd = $reference->words[$step['referenceStart'] + $step['referenceCount'] - 1]['end'];
            $bEnd = $copy->words[$step['copyStart'] + $step['copyCount'] - 1]['end'];
            $aInternal = $this->take($reference->punctuation, $aCursor, $aEnd);
            $bInternal = $this->take($copy->punctuation, $bCursor, $bEnd);
            if ('segmentation' === $step['type']) {
                // Dangling apostrophes/hyphens inside a split are already penalized lexically.
                $aInternal = array_values(array_filter($aInternal, static fn (array $token): bool => !in_array($token['value'], ["'", '-'], true)));
                $bInternal = array_values(array_filter($bInternal, static fn (array $token): bool => !in_array($token['value'], ["'", '-'], true)));
            }
            $this->appendGap($issues, $aInternal, $bInternal, $bEnd);
            $previousCopyEnd = $bEnd;
        }
        $this->appendGap($issues,
            $this->take($reference->punctuation, $aCursor, $reference->length),
            $this->take($copy->punctuation, $bCursor, $copy->length), $previousCopyEnd ?? 0);

        return $issues;
    }

    /**
     * Refine only omissions after the last copied word; initial/middle anchors stay unchanged.
     * Retained punctuation is identified by the same non-overlapping prefix/suffix convention
     * as punctuation comparison. Repeated signs prefer the prefix, not inferred sentence intent.
     *
     * @param list<Step> $steps
     *
     * @return array<int, int> original UTF-16 insertion point by omitted reference word index
     */
    public function trailingOmissionPoints(TokenizedText $reference, TokenizedText $copy, array $steps): array
    {
        $referenceTailStart = 0;
        $omitted = [];
        foreach ($steps as $step) {
            if ($step['referenceCount'] > 0 && $step['copyCount'] > 0) {
                $referenceTailStart = $reference->words[$step['referenceStart'] + $step['referenceCount'] - 1]['end'];
            } elseif (0 === $step['copyCount'] && count($copy->words) === $step['copyStart']) {
                $omitted[] = $step['referenceStart'];
            }
        }
        if ([] === $omitted) {
            return [];
        }
        $point = $copy->insertionPoint(count($copy->words));
        $a = array_values(array_filter($reference->punctuation, static fn (array $token): bool => $token['start'] >= $referenceTailStart));
        $b = array_values(array_filter($copy->punctuation, static fn (array $token): bool => $token['start'] >= $point));
        [$prefix, $aEnd, $bEnd] = $this->matchingEdges($a, $b);
        $retained = [];
        for ($i = 0; $i < $prefix; ++$i) {
            $retained[] = ['referenceStart' => $a[$i]['start'], 'copyEnd' => $b[$i]['end']];
        }
        for ($i = $aEnd, $j = $bEnd; $i < count($a); ++$i, ++$j) {
            $retained[] = ['referenceStart' => $a[$i]['start'], 'copyEnd' => $b[$j]['end']];
        }
        $points = [];
        $cursor = 0;
        foreach ($omitted as $wordIndex) {
            while (isset($retained[$cursor]) && $retained[$cursor]['referenceStart'] < $reference->words[$wordIndex]['start']) {
                $point = $retained[$cursor++]['copyEnd'];
            }
            $points[$wordIndex] = $point;
        }

        return $points;
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<Token>
     */
    private function take(array $tokens, int &$cursor, int $before): array
    {
        $result = [];
        while (isset($tokens[$cursor]) && $tokens[$cursor]['start'] < $before) {
            $result[] = $tokens[$cursor++];
        }

        return $result;
    }

    /**
     * @param list<Issue> $issues
     * @param list<Token> $a
     * @param list<Token> $b
     */
    private function appendGap(array &$issues, array $a, array $b, int $fallback): void
    {
        [$start, $aEnd, $bEnd] = $this->matchingEdges($a, $b);
        if ($aEnd === $start && $bEnd === $start) {
            return;
        }
        $hasCopy = $bEnd > $start;
        $position = $hasCopy ? $b[$start]['start']
            : ($start > 0 ? $b[$start - 1]['end'] : min($fallback, $b[$start]['start'] ?? $fallback));
        $issues[] = ['domain' => 'punctuation',
            'type' => $aEnd === $start ? 'insertion' : ($hasCopy ? 'substitution' : 'omission'),
            'start' => $position, 'end' => $hasCopy ? $b[$bEnd - 1]['end'] : $position];
    }

    /**
     * @param list<Token> $a
     * @param list<Token> $b
     *
     * @return array{int, int, int} common prefix length and exclusive ends of unmatched zones
     */
    private function matchingEdges(array $a, array $b): array
    {
        $start = 0;
        $aEnd = count($a);
        $bEnd = count($b);
        while ($start < $aEnd && $start < $bEnd && $a[$start]['value'] === $b[$start]['value']) {
            ++$start;
        }
        while ($aEnd > $start && $bEnd > $start && $a[$aEnd - 1]['value'] === $b[$bEnd - 1]['value']) {
            --$aEnd;
            --$bEnd;
        }

        return [$start, $aEnd, $bEnd];
    }
}
