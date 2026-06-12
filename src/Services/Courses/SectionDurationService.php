<?php

namespace App\Services\Courses;

use App\Entity\Sections;

final class SectionDurationService
{
    /**
     * @param iterable<Sections> $sections
     *
     * @return array<int, int>
     */
    public function calculateTotalDuration(iterable $sections): array
    {
        $sectionsTotalDuration = [];

        foreach ($sections as $section) {
            $totalDuration = 0;

            foreach ($section->getCourses() as $course) {
                $totalDuration += $course->getDurationMinutes() ?? 0;
            }

            if ($section->getId() !== null) {
                $sectionsTotalDuration[$section->getId()] = $totalDuration;
            }
        }

        return $sectionsTotalDuration;
    }
}
