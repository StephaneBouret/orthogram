<?php

namespace App\Controller\Course;

use App\Entity\Courses;
use App\Entity\Program;
use App\Repository\CoursesRepository;
use App\Repository\SectionsRepository;
use App\Security\Voter\CourseVoter;
use App\Services\Courses\CourseFileService;
use App\Services\Courses\SectionDurationService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CourseViewController extends AbstractController
{
    public function __construct(
        private readonly CoursesRepository $coursesRepository,
        private readonly SectionsRepository $sectionsRepository,
        private readonly SectionDurationService $sectionDurationService,
        private readonly CourseFileService $courseFileService,
    ) {}

    #[Route('/courses/{programSlug}/{sectionSlug}/{courseSlug}', name: 'app_course_show', methods: ['GET'])]
    public function __invoke(
        #[MapEntity(mapping: ['programSlug' => 'slug'])]
        Program $program,
        string $sectionSlug,
        string $courseSlug,
    ): Response {
        $section = $this->sectionsRepository->findOneByProgramAndSlug($program, $sectionSlug);

        if ($section === null) {
            throw $this->createNotFoundException("La section demandée n'existe pas.");
        }

        $course = $this->coursesRepository->findOneBySectionAndSlug($section, $courseSlug);

        if ($course === null) {
            throw $this->createNotFoundException("Le cours demandé n'existe pas.");
        }

        $this->denyAccessUnlessGranted(CourseVoter::VIEW, $course, "Vous n'avez pas accès à ce cours.");

        $sections = $this->sectionsRepository->findByProgramWithCourses($program);
        $navigation = $this->buildNavigation($program, $course);

        return $this->render('course/show.html.twig', [
            'program' => $program,
            'section' => $section,
            'course' => $course,
            'sections' => $sections,
            'fileContent' => $this->courseFileService->getFileContent($course),
            'previousCourse' => $navigation['previous'],
            'nextCourse' => $navigation['next'],
            'nbrCourses' => $this->coursesRepository->countByProgram($program),
            'nbrLessonsDone' => 0,
            'lessons' => [],
            'sectionsTotalDuration' => $this->sectionDurationService->calculateTotalDuration($sections),
        ]);
    }

    /**
     * @return array{previous: ?Courses, next: ?Courses}
     */
    private function buildNavigation(Program $program, Courses $currentCourse): array
    {
        $courses = $this->coursesRepository->findOrderedByProgram($program);
        $previousCourse = null;
        $nextCourse = null;

        foreach ($courses as $index => $course) {
            if ($course->getId() !== $currentCourse->getId()) {
                continue;
            }

            $previousCourse = $courses[$index - 1] ?? null;
            $nextCourse = $courses[$index + 1] ?? null;
            break;
        }

        return [
            'previous' => $previousCourse,
            'next' => $nextCourse,
        ];
    }
}
