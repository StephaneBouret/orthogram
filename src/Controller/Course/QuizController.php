<?php

namespace App\Controller\Course;

use App\Entity\Courses;
use App\Entity\User;
use App\Security\Voter\CourseVoter;
use App\Services\QuizAttemptService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class QuizController extends AbstractController
{
    #[Route('/course/{id}/quiz', name: 'app_course_quiz_state', requirements: ['id' => '\\d+'], methods: ['GET'])]
    #[Route('/course/{id}/quiz/{action}', name: 'app_course_quiz_mutate', requirements: ['id' => '\\d+', 'action' => 'start|answer|finish|restart'], methods: ['POST'])]
    public function __invoke(Courses $course, Request $request, QuizAttemptService $service, string $action = 'state'): JsonResponse
    {
        try {
            $this->denyAccessUnlessGranted(CourseVoter::VIEW, $course);
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw $this->createAccessDeniedException();
            }
            if ('state' === $action) {
                $id = $request->query->get('attemptId');
                if (null !== $id && (!ctype_digit($id) || (int) $id < 1 || (string) (int) $id !== $id)) {
                    return $this->reply(['error' => 'Identifiant de tentative invalide.'], 400);
                }
                $data = $service->state($user, $course, null === $id ? null : (int) $id);
            } else {
                if (!$this->isCsrfTokenValid('quiz_'.$course->getId(), $request->headers->get('X-CSRF-TOKEN'))) {
                    return $this->reply(['error' => 'Session ou jeton CSRF expiré. Rechargez la page.'], 403);
                }
                try {
                    $object = json_decode($request->getContent(), false, 32, JSON_THROW_ON_ERROR);
                    if (!$object instanceof \stdClass) {
                        throw new \JsonException();
                    }
                    if ('answer' === $action && isset($object->selectedIds) && !is_array($object->selectedIds)) {
                        return $this->reply(['error' => 'La sélection doit être une liste JSON.'], 400);
                    }
                    $payload = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    return $this->reply(['error' => 'Le corps de la requête doit être un objet JSON valide.'], 400);
                }
                $data = $service->mutate($user, $course, $action, $payload);
            }

            return $this->reply($data);
        } catch (HttpExceptionInterface $error) {
            return $this->reply(['error' => $error->getMessage()], $error->getStatusCode());
        } catch (AccessDeniedException) {
            return $this->reply(['error' => 'Vous n’avez plus accès à ce cours.'], 403);
        }
    }

    /** @param array<string, mixed> $data */
    private function reply(array $data, int $status = 200): JsonResponse
    {
        return $this->json($data, $status, ['Cache-Control' => 'private, no-store']);
    }
}
