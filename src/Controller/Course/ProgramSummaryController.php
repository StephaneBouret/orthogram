<?php

namespace App\Controller\Course;

use App\Entity\Program;
use App\Entity\User;
use App\Repository\CoursesRepository;
use App\Repository\LearningReminderRepository;
use App\Repository\LessonRepository;
use App\Repository\SectionsRepository;
use App\Security\Voter\CourseVoter;
use App\Services\Courses\SectionCompletionService;
use App\Services\Courses\SectionDurationService;
use App\Services\LearningReminderViewService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProgramSummaryController extends AbstractController
{
    public function __construct(
        private readonly CoursesRepository $coursesRepository,
        private readonly SectionsRepository $sectionsRepository,
        private readonly SectionDurationService $sectionDurationService,
        private readonly SectionCompletionService $sectionCompletionService,
        private readonly LessonRepository $lessonRepository,
        private readonly LearningReminderRepository $learningReminderRepository,
        private readonly LearningReminderViewService $learningReminderViewService,
    ) {
    }

    #[Route('/ma-formation', name: 'app_user_training', defaults: ['slug' => 'formation-en-orthographe'], methods: ['GET'])]
    #[Route('/courses/{slug}', name: 'app_course_program_summary', methods: ['GET'])]
    public function __invoke(
        #[MapEntity(mapping: ['slug' => 'slug'])]
        Program $program,
        Request $request,
    ): Response {
        if ('app_user_training' === $request->attributes->get('_route')) {
            $this->denyAccessUnlessGranted(CourseVoter::PROGRAM_VIEW, $program);
        }
        $canInteract = $this->isGranted(CourseVoter::PROGRAM_VIEW, $program);
        $sections = $this->sectionsRepository->findByProgramWithCourses($program);
        $coursesBySection = $this->coursesRepository->countCoursesBySections($program);
        $nbrCourses = $this->coursesRepository->countByProgram($program);
        $sectionsTotalDuration = $this->sectionDurationService->calculateTotalDuration($sections);
        $programTotalDurationMinutes = array_sum($sectionsTotalDuration);
        $user = $this->getUser();
        $nbrLessonsDone = $canInteract && $user instanceof User ? $this->lessonRepository->countDoneByUserAndProgram($user, $program) : 0;
        $completedCourseIds = $canInteract && $user instanceof User ? $this->lessonRepository->findDoneCourseIdsByUserAndProgram($user, $program) : [];
        $learningReminder = $canInteract && $user instanceof User
            ? $this->learningReminderRepository->findOneByUser($user)
            : null;

        $response = $this->render('course/program_summary.html.twig', [
            'canInteract' => $canInteract,
            'program' => $program,
            'sections' => $sections,
            'coursesBySection' => $coursesBySection,
            'nbrCourses' => $nbrCourses,
            'nbrLessonsDone' => $nbrLessonsDone,
            'completedCourseIds' => $completedCourseIds,
            'sectionCompletion' => $this->sectionCompletionService->calculate($sections, $completedCourseIds),
            'sectionsTotalDuration' => $sectionsTotalDuration,
            'programTotalDurationMinutes' => $programTotalDurationMinutes,
            'learningReminder' => null === $learningReminder
                ? null
                : $this->learningReminderViewService->present($learningReminder),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
