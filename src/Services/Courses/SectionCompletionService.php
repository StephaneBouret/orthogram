<?php

namespace App\Services\Courses;

use App\Entity\Sections;

final class SectionCompletionService
{
    /**
     * @param iterable<Sections> $sections           Sections with their courses already loaded
     * @param list<int>          $completedCourseIds
     *
     * @return array<int, bool>
     */
    public function calculate(iterable $sections, array $completedCourseIds): array
    {
        $completedIds = array_fill_keys($completedCourseIds, true);
        $completion = [];

        foreach ($sections as $section) {
            if (null === $section->getId()) {
                continue;
            }

            $isComplete = !$section->getCourses()->isEmpty();
            foreach ($section->getCourses() as $course) {
                if (!isset($completedIds[$course->getId()])) {
                    $isComplete = false;
                    break;
                }
            }

            $completion[$section->getId()] = $isComplete;
        }

        return $completion;
    }
}
