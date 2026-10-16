<?php

declare(strict_types=1);

namespace App\Enum;

enum DictationState: string
{
    case Writing = 'writing';
    case SelfCorrection = 'self_correction';
    case AwaitingCourses = 'awaiting_courses';
    case Resuming = 'resuming';
    case Reviewing = 'reviewing';
    case Completed = 'completed';
}
