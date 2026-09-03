<?php

declare(strict_types=1);

namespace App\Enum;

enum LearningReminderFrequency: string
{
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case ONCE = 'once';

    public function label(): string
    {
        return match ($this) {
            self::DAILY => 'Tous les jours',
            self::WEEKLY => 'Chaque semaine',
            self::ONCE => 'Une seule fois',
        };
    }

    public function isRecurring(): bool
    {
        return self::ONCE !== $this;
    }

    public function requiresWeekdays(): bool
    {
        return self::WEEKLY === $this;
    }

    public function requiresScheduledDate(): bool
    {
        return self::ONCE === $this;
    }
}
