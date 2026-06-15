<?php

namespace App\Controller\Course;

use App\Entity\Exercice;
use App\Entity\ExerciceAttempt;
use App\Entity\User;
use App\Repository\ExerciceAttemptRepository;
use App\Security\Voter\CourseVoter;
use App\Services\ExerciceCorrectionService;
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
        private readonly ExerciceCorrectionService $exerciceCorrectionService,
        private readonly ExerciceAttemptRepository $exerciceAttemptRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

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
    public function submit(Exercice $exercice, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGrantedToExercice($exercice);

        if ($exercice->getType() !== Exercice::TYPE_CLICK_WORDS) {
            return $this->json(['message' => 'Ce type d’exercice n’est pas encore pris en charge.'], Response::HTTP_BAD_REQUEST);
        }

        $payload = json_decode($request->getContent(), true);
        $selected = is_array($payload) && isset($payload['selected']) && is_array($payload['selected'])
            ? $payload['selected']
            : [];
        $selected = array_values(array_unique(array_filter(
            $selected,
            static fn (mixed $tokenId): bool => is_string($tokenId) && $tokenId !== ''
        )));
        $result = $this->exerciceCorrectionService->correctClickWords($exercice, $selected);
        $user = $this->getUser();

        if ($user instanceof User) {
            $attempt = (new ExerciceAttempt())
                ->setUser($user)
                ->setExercice($exercice)
                ->setScore($result['score'])
                ->setTotal($result['total'])
                ->setPercentage($result['percentage'])
                ->setSelectedTokenIds($selected)
                ->setCorrectionItems($result['items']);

            $this->entityManager->persist($attempt);
            $this->entityManager->flush();

            $result['attempt'] = [
                'id' => $attempt->getId(),
                'number' => $this->exerciceAttemptRepository->countByUserAndExercice($user, $exercice),
                'submittedAt' => $attempt->getSubmittedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return $this->json($result);
    }

    private function denyAccessUnlessGrantedToExercice(Exercice $exercice): void
    {
        if ($exercice->getCourses()->isEmpty()) {
            $this->denyAccessUnlessGranted('ROLE_USER');

            return;
        }

        foreach ($exercice->getCourses() as $course) {
            if ($this->isGranted(CourseVoter::VIEW, $course)) {
                return;
            }
        }

        $this->denyAccessUnlessGranted(CourseVoter::VIEW, $exercice->getCourses()->first());
    }
}
