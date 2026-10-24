<?php

namespace App\Controller\Course;

use App\Entity\Courses;
use App\Entity\Exercice;
use App\Entity\User;
use App\Repository\CoursesRepository;
use App\Repository\ExerciceAttemptRepository;
use App\Repository\LessonRepository;
use App\Repository\SectionsRepository;
use App\Security\Voter\CourseVoter;
use App\Services\Courses\SectionCompletionService;
use App\Services\ExerciceAttemptService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/exercise')]
class ExerciseController extends AbstractController
{
    public function __construct(
        private readonly ExerciceAttemptService $attemptService,
        private readonly ExerciceAttemptRepository $exerciceAttemptRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/{id}', name: 'app_exercise_show', methods: ['GET'])]
    public function show(Exercice $exercice): Response
    {
        $this->denyAccessUnlessGrantedToExercice($exercice);
        $user = $this->getUser();

        return $this->render('exercise/show.html.twig', [
            'exercice' => $exercice,
            'latestAttempt' => $user instanceof User ? $this->exerciceAttemptRepository->findLatestByUserAndExercice($user, $exercice) : null,
        ]);
    }

    #[Route('/{id}/submit', name: 'app_exercise_submit', methods: ['POST'])]
    public function submit(Exercice $exercice, Request $request, CoursesRepository $courses, LessonRepository $lessons, SectionsRepository $sections, SectionCompletionService $completion): JsonResponse
    {
        if (!$this->getUser() instanceof User) {
            // Preserve the firewall entry point for expired/invalidated sessions.
            $this->denyAccessUnlessGrantedToExercice($exercice);
        }
        if (!$this->isGranted(CourseVoter::INTERACT, $exercice)) {
            return new JsonResponse(['message' => 'Vous n’avez pas accès à cet exercice.'], 403, ['Cache-Control' => 'private, no-store']);
        }

        if (!$this->isCsrfTokenValid('exercise_'.$exercice->getId(), $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->json(['message' => 'Session ou jeton CSRF expiré. Rechargez la page.'], Response::HTTP_FORBIDDEN);
        }

        if (Exercice::TYPE_CLICK_WORDS !== $exercice->getType()) {
            return $this->json(['message' => 'Ce type d’exercice n’est pas encore pris en charge.'], Response::HTTP_BAD_REQUEST);
        }

        $payload = json_decode($request->getContent(), true);
        $course = null;
        if (is_array($payload) && array_key_exists('courseId', $payload)) {
            if (!is_int($payload['courseId']) || $payload['courseId'] < 1) {
                return $this->json(['message' => 'Contexte de cours invalide.'], Response::HTTP_BAD_REQUEST);
            }
            $course = $this->entityManager->find(Courses::class, $payload['courseId']);
            if (null === $course) {
                return $this->json(['message' => 'Cours introuvable.'], Response::HTTP_BAD_REQUEST);
            }
        }
        $selected = is_array($payload) && isset($payload['selected']) && is_array($payload['selected'])
            ? $payload['selected']
            : [];
        $selected = array_values(array_unique(array_filter(
            $selected,
            static fn (mixed $tokenId): bool => is_string($tokenId) && '' !== $tokenId
        )));
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $result = $this->attemptService->submit($user, $exercice, $selected, $course);
        if (null !== $course && null !== $program = $course->getSection()?->getProgram()) {
            $programSections = $sections->findByProgramWithCourses($program);
            $completedIds = $lessons->findDoneCourseIdsByUserAndProgram($user, $program);
            $context = [
                'program' => $program, 'sections' => $programSections,
                'currentSection' => $course->getSection(), 'currentCourse' => $course,
                'canInteract' => true, 'nbrCourses' => $courses->countByProgram($program),
                'nbrLessonsDone' => count($completedIds), 'completedCourseIds' => $completedIds,
                'sectionCompletion' => $completion->calculate($programSections, $completedIds),
            ];
            // Render only progress fragments after commit; the correction stays in place.
            $result['progress'] = [
                'desktop' => $this->renderView('course/program_summary/_sidebar_content.html.twig', $context + ['collapsePrefix' => 'desktop']),
                'mobile' => $this->renderView('course/program_summary/_sidebar_content.html.twig', $context + ['collapsePrefix' => 'mobile']),
            ];
        }

        return $this->json($result, headers: ['Cache-Control' => 'private, no-store']);
    }

    private function denyAccessUnlessGrantedToExercice(Exercice $exercice): void
    {
        $this->denyAccessUnlessGranted(CourseVoter::INTERACT, $exercice);
    }
}
