<?php

declare(strict_types=1);

namespace App\Enum;

use App\Entity\Courses;

enum CourseFileKind: string
{
    case Source = 'source';
    case Audio = 'audio';
    case Video = 'video';

    public function field(): string
    {
        return match ($this) {
            self::Source => 'partialFile',
            self::Audio => 'audioFile',
            self::Video => 'videoFile',
        };
    }

    public function directory(): string
    {
        return match ($this) {
            self::Source => 'files',
            self::Audio => 'audios',
            self::Video => 'videos',
        };
    }

    public function filename(Courses $course): ?string
    {
        return match ($this) {
            self::Source => $course->getPartialFileName(),
            self::Audio => $course->getAudioFileName(),
            self::Video => $course->getVideoName(),
        };
    }

    public function matches(Courses $course): bool
    {
        return match ($this) {
            self::Source => in_array($course->getContentType(), [CourseContentType::Twig, CourseContentType::Link], true),
            self::Audio => CourseContentType::Audio === $course->getContentType(),
            self::Video => CourseContentType::Video === $course->getContentType(),
        };
    }
}
