<?php

namespace App\Services\Courses;

use App\Entity\Courses;
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
        $completion = [];

        foreach ($sections as $section) {
            if (null === $section->getId()) {
                continue;
            }

            $completion[$section->getId()] = $this->areCoursesComplete($section->getCourses(), $completedCourseIds);
        }

        return $completion;
    }

    /** @param iterable<Courses> $courses
     * @param list<int> $completedCourseIds
     */
    public function areCoursesComplete(iterable $courses, array $completedCourseIds): bool
    {
        $completedIds = array_fill_keys($completedCourseIds, true);
        $hasCourses = false;
        foreach ($courses as $course) {
            $hasCourses = true;
            if (!isset($completedIds[$course->getId()])) {
                return false;
            }
        }

        return $hasCourses;
    }
}
