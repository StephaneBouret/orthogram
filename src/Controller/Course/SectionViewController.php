<?php

namespace App\Controller\Course;

use App\Entity\Program;
use App\Repository\SectionsRepository;
use App\Security\Voter\CourseVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SectionViewController extends AbstractController
{
    public function __construct(
        private readonly SectionsRepository $sectionsRepository,
    ) {
    }

    #[Route('/courses/{programSlug}/{sectionSlug}', name: 'app_course_section', methods: ['GET'])]
    public function __invoke(
        #[MapEntity(mapping: ['programSlug' => 'slug'])]
        Program $program,
        string $sectionSlug,
    ): Response {
        $section = $this->sectionsRepository->findOneByProgramAndSlug($program, $sectionSlug);

        if (null === $section) {
            throw $this->createNotFoundException("La section demandée n'existe pas.");
        }

        $this->denyAccessUnlessGranted(CourseVoter::SECTION_VIEW, $section, "Vous n'avez pas accès à cette section.");

        $response = $this->redirectToRoute('app_course_program_summary', ['slug' => $program->getSlug()], Response::HTTP_FOUND);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
