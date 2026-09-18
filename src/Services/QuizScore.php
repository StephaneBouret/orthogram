<?php

namespace App\Services;

final class QuizScore
{
    public static function percentage(int $score, int $total): ?int
    {
        return $total > 0 ? (int) round(100 * $score / $total) : null;
    }
}
