<?php

namespace App\Tests\Services;

use App\Services\QuizScore;
use PHPUnit\Framework\TestCase;

final class QuizScoreTest extends TestCase
{
    public function testPercentageUsesIntegerRoundingAndUnknownForZeroTotal(): void
    {
        self::assertSame(33, QuizScore::percentage(1, 3));
        self::assertSame(67, QuizScore::percentage(2, 3));
        self::assertSame(13, QuizScore::percentage(1, 8));
        self::assertSame(0, QuizScore::percentage(0, 2));
        self::assertSame(100, QuizScore::percentage(2, 2));
        self::assertNull(QuizScore::percentage(0, 0));
    }
}
