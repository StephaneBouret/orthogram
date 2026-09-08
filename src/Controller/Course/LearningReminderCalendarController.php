<?php

declare(strict_types=1);

namespace App\Controller\Course;

use App\Dto\LearningReminderPayload;
use App\Entity\Program;
use App\Entity\User;
use App\Security\Voter\CourseVoter;
use App\Services\LearningReminderCalendarService;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class LearningReminderCalendarController extends AbstractController
{
    public function __construct(
        private readonly LearningReminderCalendarService $calendar,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        '/courses/{slug}/learning-reminder/calendar/{format}',
        name: 'app_course_learning_reminder_calendar',
        requirements: ['format' => 'google|ics'],
        methods: ['POST'],
        format: 'json',
    )]
    #[IsGranted(
        CourseVoter::PROGRAM_VIEW,
        subject: 'program',
        message: "Vous n'avez pas accès à ce programme.",
        statusCode: Response::HTTP_FORBIDDEN,
    )]
    #[IsCsrfTokenValid(
        'learning_reminder_calendar',
        tokenKey: 'X-CSRF-TOKEN',
        tokenSource: IsCsrfTokenValid::SOURCE_HEADER,
    )]
    public function export(
        #[MapEntity(mapping: ['slug' => 'slug'])] Program $program,
        #[CurrentUser] ?User $user,
        #[MapRequestPayload(acceptFormat: 'json', serializationContext: ['allow_extra_attributes' => false], validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] LearningReminderPayload $payload,
        string $format,
    ): Response {
        if (!$user instanceof User) {
            return $this->json(['error' => ['code' => 'access_denied', 'message' => 'Vous devez être connecté.']], Response::HTTP_FORBIDDEN);
        }
        try {
            $prepared = $this->calendar->prepare($payload, $this->clock->now());
        } catch (\InvalidArgumentException $exception) {
            return $this->json([
                'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'detail' => $exception->getMessage(),
                'violations' => [['propertyPath' => 'scheduledDate', 'title' => $exception->getMessage()]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY, ['Cache-Control' => 'private, no-store']);
        }
        if ('google' === $format && null !== $prepared['googleUrl']) {
            return $this->json([
                'url' => $prepared['googleUrl'],
                'expiresAt' => $prepared['expiresAt'],
                'notice' => $prepared['notice'],
            ], Response::HTTP_OK, ['Cache-Control' => 'private, no-store']);
        }

        $headers = [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="orthogram.ics"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Orthogram-Expires-At' => $prepared['expiresAt'],
            'X-Orthogram-Notice' => rawurlencode($prepared['notice']),
        ];
        if (null !== $prepared['separateFirstNotice']) {
            $headers['X-Orthogram-Separate-First-Notice'] = rawurlencode($prepared['separateFirstNotice']);
        }

        return new Response($prepared['ics'], Response::HTTP_OK, $headers);
    }
}
