<?php

namespace App\Controller\Course;

use App\Entity\Program;
use App\Entity\User;
use App\Repository\CoursesRepository;
use App\Repository\LessonRepository;
use App\Repository\SectionsRepository;
use App\Security\Voter\CourseVoter;
use App\Services\Courses\SectionDurationService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProgramSummaryController extends AbstractController
{
    public function __construct(
        private readonly CoursesRepository $coursesRepository,
        private readonly SectionsRepository $sectionsRepository,
        private readonly SectionDurationService $sectionDurationService,
        private readonly LessonRepository $lessonRepository,
    ) {
    }

    #[Route('/courses/{slug}', name: 'app_course_program_summary', methods: ['GET'])]
    public function __invoke(
        #[MapEntity(mapping: ['slug' => 'slug'])]
        Program $program,
    ): Response {
        $this->denyAccessUnlessGranted(CourseVoter::PROGRAM_VIEW, $program, "Vous n'avez pas accès à ce programme.");

        $sections = $this->sectionsRepository->findByProgramWithCourses($program);
        $coursesBySection = $this->coursesRepository->countCoursesBySections($program);
        $nbrCourses = $this->coursesRepository->countByProgram($program);
        $sectionsTotalDuration = $this->sectionDurationService->calculateTotalDuration($sections);
        $user = $this->getUser();
        $nbrLessonsDone = $user instanceof User ? $this->lessonRepository->countDoneByUserAndProgram($user, $program) : 0;
        $completedCourseIds = $user instanceof User ? $this->lessonRepository->findDoneCourseIdsByUserAndProgram($user, $program) : [];

        return $this->render('course/program_summary.html.twig', [
            'program' => $program,
            'sections' => $sections,
            'coursesBySection' => $coursesBySection,
            'nbrCourses' => $nbrCourses,
            'nbrLessonsDone' => $nbrLessonsDone,
            'completedCourseIds' => $completedCourseIds,
            'sectionsTotalDuration' => $sectionsTotalDuration,
        ]);
    }
}
