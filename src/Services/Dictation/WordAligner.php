<?php

declare(strict_types=1);

namespace App\Services\Dictation;

/**
 * @phpstan-type Step array{type: string, referenceStart: int, referenceCount: int, copyStart: int, copyCount: int}
 *
 * @phpstan-import-type Unit from TokenizedText
 */
final class WordAligner
{
    // Larger than the maximum number of canonical matches: cost always dominates tie-breaking.
    private const WEIGHT = 1024;

    /**
     * @param array<int, list<list<Unit>>> $variants
     *
     * @return list<Step>
     */
    public function align(TokenizedText $reference, TokenizedText $copy, array $variants): array
    {
        $a = $reference->comparisonUnits();
        $b = $copy->comparisonUnits();
        $m = count($a);
        $n = count($b);
        $aGroups = $this->groups($reference);
        $bGroups = $this->groups($copy);
        $rows = [range(0, $n)];
        foreach ($rows[0] as &$value) {
            $value *= self::WEIGHT;
        }
        unset($value);
        // One byte per cell, never an object, PHP array or complete path per cell.
        $trace = [str_repeat(chr(3), $n + 1)];
        for ($i = 1; $i <= $m; ++$i) {
            $row = [$i * self::WEIGHT];
            $directions = chr(2).str_repeat(chr(0), $n);
            $previous = $rows[($i - 1) % 4];
            $alternatives = $variants[$i - 1] ?? [];
            for ($j = 1; $j <= $n; ++$j) {
                $sameWord = $a[$i - 1]['value'] === $b[$j - 1]['value'];
                $exact = $sameWord && TokenizedText::sameSpacing($a[$i - 1], $b[$j - 1]);
                $best = $previous[$j - 1] + ($exact ? -1 : self::WEIGHT);
                $direction = $exact ? 0 : ($sameWord ? 4 : 1);
                foreach ($alternatives as $alternative) {
                    $size = count($alternative);
                    if ($j < $size) {
                        continue;
                    }
                    foreach ($alternative as $k => $word) {
                        if ($word['value'] !== $b[$j - $size + $k]['value'] || !TokenizedText::sameSpacing($word, $b[$j - $size + $k])) {
                            continue 2;
                        }
                    }
                    $candidate = $previous[$j - $size];
                    if ($candidate < $best) {
                        $best = $candidate;
                        $direction = 20 + $size;
                    }
                }
                foreach ($aGroups[$i] as $aSize => $aValue) {
                    foreach ($bGroups[$j] as $bSize => $bValue) {
                        $sameLetters = $aValue['letters'] === $bValue['letters'];
                        // A fused elision is one erroneous written word, even with accent/case errors
                        // (explicit acceptance case: l’école / lecole). Never a zero-cost match.
                        $fusedElision = (1 === $aSize || 1 === $bSize) && $aSize !== $bSize
                            && ($aValue['elision'] || $bValue['elision'])
                            && $aValue['folded'] === $bValue['folded'];
                        if ((1 === $aSize && 1 === $bSize) || (!$sameLetters && !$fusedElision)
                            || !TokenizedText::sameSpacing($a[$i - 1], $b[$j - 1])) {
                            continue;
                        }
                        $candidate = $rows[($i - $aSize) % 4][$j - $bSize] + self::WEIGHT;
                        if ($candidate < $best) {
                            $best = $candidate;
                            $direction = 10 + ($aSize - 1) * 3 + $bSize - 1;
                        }
                    }
                }
                $candidate = $previous[$j] + self::WEIGHT;
                if ($candidate < $best) {
                    $best = $candidate;
                    $direction = 2;
                }
                $candidate = $row[$j - 1] + self::WEIGHT;
                if ($candidate < $best) {
                    $best = $candidate;
                    $direction = 3;
                }
                // Evaluate the prefix together with the omission of its following unit.
                // Only that omitted join is waived: prefix spelling and spacing before
                // its apostrophe still cost an error. Subsequent omissions use direction 2.
                if ($i >= 2 && true === $a[$i - 2]['joined'] && false === $b[$j - 1]['joined']) {
                    $samePrefix = $a[$i - 2]['value'] === $b[$j - 1]['value'];
                    $exactPrefix = $samePrefix && $a[$i - 2]['spaced'] === $b[$j - 1]['spaced'];
                    // A waived boundary is not an exact boundary match: no tie-break bonus.
                    // Otherwise omission + insertion could displace separation + substitution.
                    $candidate = $rows[($i - 2) % 4][$j - 1] + self::WEIGHT + ($exactPrefix ? 0 : self::WEIGHT);
                    // At equal cost, keep the elided prefix paired with the elided prefix.
                    if ($candidate <= $best) {
                        $best = $candidate;
                        $direction = $exactPrefix ? 24 : ($samePrefix ? 26 : 25);
                    }
                }
                $row[$j] = $best;
                $directions[$j] = chr($direction);
            }
            $rows[$i % 4] = $row;
            $trace[] = $directions;
        }

        $steps = [];
        for ($i = $m, $j = $n; $i > 0 || $j > 0;) {
            $direction = ord($trace[$i][$j]);
            if ($direction >= 24 && $direction <= 26) {
                // Expand the compound transition into the existing public-independent steps.
                $steps[] = ['type' => 'omission', 'referenceStart' => $i - 1, 'referenceCount' => 1, 'copyStart' => $j, 'copyCount' => 0];
                $i -= 2;
                --$j;
                $type = match ($direction) {
                    24 => 'match', 25 => 'substitution', 26 => 'segmentation',
                };
                $steps[] = ['type' => $type, 'referenceStart' => $i, 'referenceCount' => 1, 'copyStart' => $j, 'copyCount' => 1];
                continue;
            }
            $aSize = 3 === $direction ? 0 : 1;
            $bSize = 2 === $direction ? 0 : 1;
            $type = match ($direction) {
                0 => 'match', 1 => 'substitution', 2 => 'omission', 3 => 'insertion', 4 => 'segmentation',
                21, 22, 23 => 'variant', default => 'segmentation',
            };
            if ('segmentation' === $type && 4 !== $direction) {
                $aSize = intdiv($direction - 10, 3) + 1;
                $bSize = ($direction - 10) % 3 + 1;
            } elseif ('variant' === $type) {
                $bSize = $direction - 20;
            }
            $i -= $aSize;
            $j -= $bSize;
            $steps[] = ['type' => $type, 'referenceStart' => $i, 'referenceCount' => $aSize, 'copyStart' => $j, 'copyCount' => $bSize];
        }

        return array_reverse($steps);
    }

    /** @return array<int, array<int, array{letters: string, folded: string, elision: bool}>> Groups indexed by exclusive end, then unit count. */
    private function groups(TokenizedText $text): array
    {
        $groups = [];
        foreach ($text->words as $index => $word) {
            $value = str_replace(["'", '-'], '', $word['value']);
            $folded = $this->fusionLetters($value);
            $elision = $word['elision'] ?? false;
            $groups[$index + 1] = [1 => ['letters' => $value, 'folded' => $folded, 'elision' => $elision]];
            for ($size = 2; $size <= 3 && $size <= $index + 1; ++$size) {
                $left = $index - $size + 1;
                // Other punctuation must never be swallowed by a lexical segmentation operation.
                if (1 !== preg_match("~\\A[\\p{Z}\\x{0009}-\\x{000D}'’\\x{2010}\\x{2011}-]*\\z~u", $text->gaps[$left + 1])) {
                    break;
                }
                $prefix = str_replace(["'", '-'], '', $text->words[$left]['value']);
                $value = $prefix.$value;
                $folded = $this->fusionLetters($prefix).$folded;
                $elision = $elision || ($text->words[$left]['elision'] ?? false);
                $groups[$index + 1][$size] = ['letters' => $value, 'folded' => $folded, 'elision' => $elision];
            }
        }

        return $groups;
    }

    private function fusionLetters(string $word): string
    {
        $decomposed = \Normalizer::normalize($word, \Normalizer::FORM_D);
        if (false === $decomposed || null === ($result = preg_replace('~\\p{M}+~u', '', $decomposed))) {
            throw new \InvalidArgumentException('Analyse Unicode impossible.');
        }

        return mb_strtolower($result, 'UTF-8');
    }
}
