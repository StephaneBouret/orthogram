<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Entity\Courses;
use App\Enum\CourseFileKind;
use Twig\Environment;

final class CourseFileService
{
    public function __construct(
        private readonly CourseFileStorage $storage,
        private readonly Environment $twig,
    ) {
    }

    public function getFileContent(Courses $course): ?string
    {
        $fullPath = $this->storage->resolve($course, CourseFileKind::Source);
        if (null === $fullPath) {
            return null;
        }

        $content = file_get_contents($fullPath);

        if (false === $content) {
            return null;
        }

        return $this->twig->createTemplate($content)->render([
            'course' => $course,
            'section' => $course->getSection(),
            'program' => $course->getSection()?->getProgram(),
        ]);
    }
}
