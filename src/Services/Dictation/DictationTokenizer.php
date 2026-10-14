<?php

declare(strict_types=1);

namespace App\Services\Dictation;

final class DictationTokenizer
{
    // A combining mark extends a base letter/number; a standalone mark is not a word.
    private const WORD = "[\\p{L}\\p{N}][\\p{L}\\p{M}\\p{N}]*+(?:['’\\x{2010}\\x{2011}-][\\p{L}\\p{N}][\\p{L}\\p{M}\\p{N}]*+)*+";
    private const SEPARATORS = '[\\p{Z}\\x{0009}-\\x{000D}]+';
    // Deliberately finite: unrecognized apostrophized words remain a single unit.
    private const ELISION_PREFIXES = ['c', 'd', 'j', 'l', 'm', 'n', 's', 't', 'qu', 'jusqu', 'lorsqu', 'puisqu'];
    private const LEXICAL_EXCEPTIONS = ["aujourd'hui"];
    // Only ' and ’ are equivalent. The other quote-like signs are retained as lexical errors.
    private const APOSTROPHE_CANDIDATES = "['’‘ʼ`´\"“”]";

    public function countWords(string $text): int
    {
        return count($this->tokenize($text)->words);
    }

    public function tokenize(string $text, int $maxWords = CorrectionContract::MAX_COPY_WORDS, int $maxLength = CorrectionContract::MAX_UTF16_LENGTH): TokenizedText
    {
        // UTF-8 needs at most 3 bytes per UTF-16 unit. Bound allocation before decoding.
        if (strlen($text) > 3 * $maxLength) {
            throw new \InvalidArgumentException('Limite de longueur dépassée.');
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new \InvalidArgumentException('Encodage UTF-8 invalide.');
        }
        $length = self::utf16Length($text);
        if ($length > $maxLength) {
            throw new \InvalidArgumentException('Limite de longueur dépassée.');
        }

        $exceptions = implode('|', array_map(static fn (string $word): string => str_replace("'", self::APOSTROPHE_CANDIDATES, preg_quote($word, '~')), self::LEXICAL_EXCEPTIONS));
        $exception = '(?i:'.$exceptions.')(?![\\p{L}\\p{M}\\p{N}\'’\\x{2010}\\x{2011}-])';
        $elision = '(?i:'.implode('|', self::ELISION_PREFIXES).')(?:'.self::SEPARATORS.')?'.self::APOSTROPHE_CANDIDATES.'++';
        $matched = preg_match_all('~(?<exception>'.$exception.')|(?<elision>'.$elision.')|(?<word>'.self::WORD.')|(?<space>'.self::SEPARATORS.')|(?<other>\\X)~u', $text, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        if (false === $matched) {
            throw new \InvalidArgumentException('Analyse Unicode impossible.');
        }
        $words = [];
        $punctuation = [];
        $gaps = [''];
        $offset = 0;
        foreach ($matches as $match) {
            $raw = $match[0];
            $end = $offset + self::utf16Length($raw);
            if (null !== $match['word'] || null !== $match['elision'] || null !== $match['exception']) {
                if (count($words) >= $maxWords) {
                    throw new \InvalidArgumentException('Limite de mots dépassée.');
                }
                $isElision = null !== $match['elision'];
                $value = $isElision ? preg_replace('~'.self::SEPARATORS.'~u', '', $raw) : $raw;
                if (null === $value) {
                    throw new \InvalidArgumentException('Analyse Unicode impossible.');
                }
                $words[] = ['value' => $this->normalize($value), 'start' => $offset, 'end' => $end,
                    'elision' => $isElision, 'spaced' => $value !== $raw];
                $gaps[] = '';
            } else {
                $gaps[count($words)] .= $raw;
                if (null !== $match['other']) {
                    $punctuation[] = ['value' => $this->normalize($raw), 'start' => $offset, 'end' => $end];
                }
            }
            $offset = $end;
        }

        return new TokenizedText($text, $length, $words, $punctuation, $gaps);
    }

    public static function utf16Length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    private function normalize(string $text): string
    {
        $normalized = \Normalizer::normalize($text, \Normalizer::FORM_C);
        if (false === $normalized) {
            throw new \InvalidArgumentException('Normalisation Unicode impossible.');
        }

        return strtr($normalized, ['’' => "'", '‐' => '-', '‑' => '-']);
    }
}
