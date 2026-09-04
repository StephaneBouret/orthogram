<?php

declare(strict_types=1);

namespace App\Enum;

enum LearningReminderProcessingOutcome
{
    case SENT;
    case RESCHEDULED;
    case DISABLED;
}
