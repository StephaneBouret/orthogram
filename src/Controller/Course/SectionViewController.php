<?php

namespace App\Controller\Course;

use App\Entity\Program;
use App\Repository\CoursesRepository;
use App\Repository\SectionsRepository;
use App\Security\Voter\CourseVoter;
use App\Services\Courses\SectionDurationService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SectionViewController extends AbstractController
{
    public function __construct(
        private readonly CoursesRepository $coursesRepository,
        private readonly SectionsRepository $sectionsRepository,
        private readonly SectionDurationService $sectionDurationService,
    ) {}

    #[Route('/courses/{programSlug}/{sectionSlug}', name: 'app_course_section', methods: ['GET'])]
    public function __invoke(
        #[MapEntity(mapping: ['programSlug' => 'slug'])]
        Program $program,
        string $sectionSlug,
    ): Response {
        $section = $this->sectionsRepository->findOneByProgramAndSlug($program, $sectionSlug);

        if ($section === null) {
            throw $this->createNotFoundException("La section demandée n'existe pas.");
        }

        $this->denyAccessUnlessGranted(CourseVoter::SECTION_VIEW, $section, "Vous n'avez pas accès à cette section.");

        $sections = $this->sectionsRepository->findByProgramWithCourses($program);
        $nbrCourses = $this->coursesRepository->countByProgram($program);
        $count = $this->coursesRepository->countNumberCoursesBySection($section);
        $sectionsTotalDuration = $this->sectionDurationService->calculateTotalDuration($sections);

        return $this->render('course/section.html.twig', [
            'program' => $program,
            'section' => $section,
            'sections' => $sections,
            'count' => $count,
            'nbrCourses' => $nbrCourses,
            'nbrLessonsDone' => 0,
            'lessons' => [],
            'sectionsTotalDuration' => $sectionsTotalDuration,
        ]);
    }
}
