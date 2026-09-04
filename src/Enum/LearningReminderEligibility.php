<?php

declare(strict_types=1);

namespace App\Enum;

enum LearningReminderEligibility
{
    case ELIGIBLE;
    case TEMPORARILY_INELIGIBLE;
    case PERMANENTLY_INELIGIBLE;
}
