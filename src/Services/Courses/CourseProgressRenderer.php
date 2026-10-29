<?php

namespace App\Services\Courses;

use App\Entity\Courses;
use App\Entity\User;
use App\Repository\CoursesRepository;
use App\Repository\LessonRepository;
use App\Repository\SectionsRepository;
use Twig\Environment;

final class CourseProgressRenderer
{
    public function __construct(
        private readonly CoursesRepository $courses,
        private readonly LessonRepository $lessons,
        private readonly SectionsRepository $sections,
        private readonly SectionCompletionService $completion,
        private readonly Environment $twig,
    ) {
    }

    /** @return array{desktop: string, mobile: string}|null */
    public function render(User $user, Courses $course): ?array
    {
        $program = $course->getSection()?->getProgram();
        if (null === $program) {
            return null;
        }
        $sections = $this->sections->findByProgramWithCourses($program);
        $completedIds = $this->lessons->findDoneCourseIdsByUserAndProgram($user, $program);
        $context = [
            'program' => $program, 'sections' => $sections,
            'currentSection' => $course->getSection(), 'currentCourse' => $course,
            'canInteract' => true, 'nbrCourses' => $this->courses->countByProgram($program),
            'nbrLessonsDone' => count($completedIds), 'completedCourseIds' => $completedIds,
            'sectionCompletion' => $this->completion->calculate($sections, $completedIds),
        ];

        return [
            'desktop' => $this->twig->render('course/program_summary/_sidebar_content.html.twig', $context + ['collapsePrefix' => 'desktop']),
            'mobile' => $this->twig->render('course/program_summary/_sidebar_content.html.twig', $context + ['collapsePrefix' => 'mobile']),
        ];
    }
}
