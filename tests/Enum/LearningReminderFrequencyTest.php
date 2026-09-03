<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\LearningReminderFrequency;
use PHPUnit\Framework\TestCase;

final class LearningReminderFrequencyTest extends TestCase
{
    public function testValuesAndLabels(): void
    {
        self::assertSame('daily', LearningReminderFrequency::DAILY->value);
        self::assertSame('weekly', LearningReminderFrequency::WEEKLY->value);
        self::assertSame('once', LearningReminderFrequency::ONCE->value);

        self::assertSame('Tous les jours', LearningReminderFrequency::DAILY->label());
        self::assertSame('Chaque semaine', LearningReminderFrequency::WEEKLY->label());
        self::assertSame('Une seule fois', LearningReminderFrequency::ONCE->label());
    }

    public function testFrequencyCapabilities(): void
    {
        self::assertTrue(LearningReminderFrequency::DAILY->isRecurring());
        self::assertFalse(LearningReminderFrequency::DAILY->requiresWeekdays());
        self::assertFalse(LearningReminderFrequency::DAILY->requiresScheduledDate());

        self::assertTrue(LearningReminderFrequency::WEEKLY->isRecurring());
        self::assertTrue(LearningReminderFrequency::WEEKLY->requiresWeekdays());
        self::assertFalse(LearningReminderFrequency::WEEKLY->requiresScheduledDate());

        self::assertFalse(LearningReminderFrequency::ONCE->isRecurring());
        self::assertFalse(LearningReminderFrequency::ONCE->requiresWeekdays());
        self::assertTrue(LearningReminderFrequency::ONCE->requiresScheduledDate());
    }
}
