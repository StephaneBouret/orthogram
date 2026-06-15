<?php

namespace App\Enum;

enum CourseContentType: string
{
    case Twig = 'twig';
    case Audio = 'audio';
    case Video = 'video';
    case Quiz = 'quiz';
    case Exercise = 'exercise';
    case Link = 'link';

    public function label(): string
    {
        return match ($this) {
            self::Twig => 'Twig',
            self::Audio => 'Audio',
            self::Video => 'Vidéo',
            self::Quiz => 'Quiz',
            self::Exercise => 'Exercice',
            self::Link => 'Lien',
        };
    }

    public static function choices(): array
    {
        $choices = [];

        foreach (self::cases() as $case) {
            $choices[$case->label()] = $case;
        }

        return $choices;
    }
}
