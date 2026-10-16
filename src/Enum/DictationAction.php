<?php

declare(strict_types=1);

namespace App\Enum;

enum DictationAction: string
{
    case Save = 'save';
    case Evaluate = 'evaluate';
    case Close = 'close';
    case Resume = 'resume';
    case Finalize = 'finalize';
}
