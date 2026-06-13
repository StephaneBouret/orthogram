<?php

namespace App\Controller\Course;

use App\Entity\Courses;
use App\Entity\Program;
use App\Form\CourseSearchType;
use App\Repository\ProgramRepository;
use App\Security\Voter\CourseVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CourseSearchController extends AbstractController
{
    public function __construct(
        private readonly ProgramRepository $programRepository,
    ) {}

    public function searchBar(string $programSlug): Response
    {
        $program = $this->programRepository->findOneBy(['slug' => $programSlug]);

        if (!$program instanceof Program) {
            throw $this->createNotFoundException("Le programme demandé n'existe pas.");
        }

        $this->denyAccessUnlessGranted(CourseVoter::PROGRAM_VIEW, $program, "Vous n'avez pas accès à ce programme.");

        $form = $this->createForm(CourseSearchType::class, null, [
            'program_slug' => $programSlug,
            'action' => $this->generateUrl('app_course_search', ['programSlug' => $programSlug]),
        ]);

        return $this->render('course/search/_form.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/course-search/{programSlug}', name: 'app_course_search', methods: ['GET', 'POST'])]
    public function search(string $programSlug, Request $request): Response
    {
        $program = $this->programRepository->findOneBy(['slug' => $programSlug]);

        if (!$program instanceof Program) {
            throw $this->createNotFoundException("Le programme demandé n'existe pas.");
        }

        $this->denyAccessUnlessGranted(CourseVoter::PROGRAM_VIEW, $program, "Vous n'avez pas accès à ce programme.");

        $form = $this->createForm(CourseSearchType::class, null, [
            'program_slug' => $programSlug,
        ]);
        $form->handleRequest($request);

        $course = $form->isSubmitted() && $form->isValid() ? $form->get('course')->getData() : null;

        if ($course instanceof Courses) {
            return $this->redirectToRoute('app_course_show', $this->getCourseRouteParameters($course));
        }

        return $this->redirectToRoute('app_course_program_summary', ['slug' => $programSlug]);
    }

    #[Route('/course/details/{id}', name: 'app_course_details', methods: ['GET'])]
    public function details(Courses $course): JsonResponse
    {
        $this->denyAccessUnlessGranted(CourseVoter::VIEW, $course, "Vous n'avez pas accès à ce cours.");

        return $this->json([
            'url' => $this->generateUrl('app_course_show', $this->getCourseRouteParameters($course)),
        ]);
    }

    /**
     * @return array{programSlug: string, sectionSlug: string, courseSlug: string}
     */
    private function getCourseRouteParameters(Courses $course): array
    {
        return [
            'programSlug' => (string) $course->getSection()?->getProgram()?->getSlug(),
            'sectionSlug' => (string) $course->getSection()?->getSlug(),
            'courseSlug' => (string) $course->getSlug(),
        ];
    }
}
