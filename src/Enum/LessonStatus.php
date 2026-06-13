<?php

namespace App\Enum;

enum LessonStatus: string
{
    case STUDY = 'study';
    case DONE = 'done';

    public function label(): string
    {
        return match ($this) {
            self::STUDY => 'En cours',
            self::DONE => 'Terminée',
        };
    }
}
