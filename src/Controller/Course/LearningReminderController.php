<?php

declare(strict_types=1);

namespace App\Controller\Course;

use App\Dto\LearningReminderPayload;
use App\Entity\LearningReminder;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\LearningReminderFrequency;
use App\Repository\LearningReminderRepository;
use App\Security\Voter\CourseVoter;
use App\Services\LearningReminderNextRunCalculator;
use App\Services\LearningReminderViewService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class LearningReminderController extends AbstractController
{
    public function __construct(
        private readonly LearningReminderRepository $learningReminderRepository,
        private readonly LearningReminderNextRunCalculator $nextRunCalculator,
        private readonly LearningReminderViewService $viewService,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        '/courses/{slug}/learning-reminder',
        name: 'app_course_learning_reminder_upsert',
        methods: ['POST'],
    )]
    #[IsGranted(
        CourseVoter::PROGRAM_VIEW,
        subject: 'program',
        message: "Vous n'avez pas accès à ce programme.",
        statusCode: Response::HTTP_FORBIDDEN,
    )]
    #[IsCsrfTokenValid(
        'learning_reminder_upsert',
        tokenKey: 'X-CSRF-TOKEN',
        tokenSource: IsCsrfTokenValid::SOURCE_HEADER,
    )]
    public function upsert(
        #[MapEntity(mapping: ['slug' => 'slug'])]
        Program $program,
        #[CurrentUser]
        ?User $user,
        #[MapRequestPayload(
            acceptFormat: 'json',
            serializationContext: ['allow_extra_attributes' => false],
            validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY,
        )]
        LearningReminderPayload $payload,
    ): JsonResponse {
        if (!$user instanceof User) {
            return $this->json([
                'error' => [
                    'code' => 'access_denied',
                    'message' => 'Vous devez être connecté.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        $frequency = LearningReminderFrequency::from($payload->frequency);
        $reminderTime = $this->parseTime($payload->reminderTime);
        $scheduledDate = null === $payload->scheduledDate
            ? null
            : $this->parseDate($payload->scheduledDate);

        /** @var list<int> $weekdays */
        $weekdays = array_values(array_map(
            static fn (mixed $weekday): int => (int) $weekday,
            $payload->weekdays,
        ));

        $now = $this->clock->now();

        try {
            $nextRunAt = $this->nextRunCalculator->calculate(
                $frequency,
                $reminderTime,
                $weekdays,
                $scheduledDate,
                $payload->timezone,
                $now,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->validationError('scheduledDate', $exception->getMessage());
        }

        $reminder = $this->learningReminderRepository->findOneByUser($user);
        $created = !$reminder instanceof LearningReminder;

        if ($created) {
            $reminder = LearningReminder::create(
                $user,
                $frequency,
                $reminderTime,
                $weekdays,
                $scheduledDate,
                $payload->timezone,
                $nextRunAt,
                $now,
            );
            $this->entityManager->persist($reminder);
        } else {
            $reminder->reconfigure(
                $frequency,
                $reminderTime,
                $weekdays,
                $scheduledDate,
                $payload->timezone,
                $nextRunAt,
                $now,
            );
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json([
                'error' => [
                    'code' => 'learning_reminder_conflict',
                    'message' => 'Un rappel vient déjà d’être enregistré. Rechargez la page puis réessayez.',
                ],
            ], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'message' => $created ? 'Rappel enregistré.' : 'Rappel mis à jour.',
            'reminder' => $this->viewService->present($reminder),
        ], $created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route(
        '/courses/{slug}/learning-reminder/disable',
        name: 'app_course_learning_reminder_disable',
        methods: ['POST'],
    )]
    #[IsGranted(
        CourseVoter::PROGRAM_VIEW,
        subject: 'program',
        message: "Vous n'avez pas accès à ce programme.",
        statusCode: Response::HTTP_FORBIDDEN,
    )]
    #[IsCsrfTokenValid(
        'learning_reminder_disable',
        tokenKey: 'X-CSRF-TOKEN',
        tokenSource: IsCsrfTokenValid::SOURCE_HEADER,
    )]
    public function disable(
        #[MapEntity(mapping: ['slug' => 'slug'])]
        Program $program,
        #[CurrentUser]
        ?User $user,
    ): JsonResponse {
        if (!$user instanceof User) {
            return $this->json([
                'error' => [
                    'code' => 'access_denied',
                    'message' => 'Vous devez être connecté.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        $reminder = $this->learningReminderRepository->findOneByUser($user);

        if (!$reminder instanceof LearningReminder) {
            return $this->json([
                'error' => [
                    'code' => 'learning_reminder_not_found',
                    'message' => 'Aucun rappel n’a été trouvé.',
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        $reminder->disable($this->clock->now());
        $this->entityManager->flush();

        return $this->json([
            'message' => 'Rappel désactivé.',
            'reminder' => $this->viewService->present($reminder),
        ]);
    }

    private function parseTime(string $value): \DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat(
            '!H:i',
            $value,
            new \DateTimeZone('UTC'),
        );

        if (false === $time || $time->format('H:i') !== $value) {
            throw new \LogicException('L’heure validée ne peut pas être convertie.');
        }

        return $time;
    }

    private function parseDate(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new \DateTimeZone('UTC'),
        );

        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \LogicException('La date validée ne peut pas être convertie.');
        }

        return $date;
    }

    private function validationError(string $propertyPath, string $message): JsonResponse
    {
        return $this->json([
            'type' => 'https://symfony.com/errors/validation',
            'title' => 'Validation Failed',
            'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
            'detail' => $message,
            'violations' => [
                [
                    'propertyPath' => $propertyPath,
                    'title' => $message,
                ],
            ],
        ], Response::HTTP_UNPROCESSABLE_ENTITY, [
            'Content-Type' => 'application/problem+json',
        ]);
    }
}
