<?php

namespace App\Services\Courses;

final class CourseDurationEstimator
{
    private const WORDS_PER_MINUTE = 180;
    private const SECONDS_PER_IMAGE = 10;
    private const SECONDS_PER_TABLE = 20;

    public function estimateReadingDuration(string $html): int
    {
        $imageCount = preg_match_all('/<img\b/i', $html);
        $tableCount = preg_match_all('/<table\b/i', $html);
        $wordCount = $this->countWords($this->extractReadableText($html));

        $readingSeconds = ($wordCount / self::WORDS_PER_MINUTE) * 60;
        $mediaSeconds = ($imageCount * self::SECONDS_PER_IMAGE) + ($tableCount * self::SECONDS_PER_TABLE);
        $totalSeconds = $readingSeconds + $mediaSeconds;

        return max(1, (int) ceil($totalSeconds / 60));
    }

    private function extractReadableText(string $html): string
    {
        $html = preg_replace('/\{#.*?#\}/s', ' ', $html) ?? $html;
        $html = preg_replace('/\{%.*?%\}/s', ' ', $html) ?? $html;
        $html = preg_replace('/\{\{.*?\}\}/s', ' ', $html) ?? $html;

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function countWords(string $text): int
    {
        preg_match_all('/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', $text, $matches);

        return count($matches[0]);
    }
}
