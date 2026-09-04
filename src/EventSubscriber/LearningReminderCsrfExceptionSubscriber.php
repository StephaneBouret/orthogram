<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;

final class LearningReminderCsrfExceptionSubscriber implements EventSubscriberInterface
{
    private const ROUTES = [
        'app_course_learning_reminder_upsert',
        'app_course_learning_reminder_disable',
    ];

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof InvalidCsrfTokenException) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');

        if (!\is_string($route) || !\in_array($route, self::ROUTES, true)) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'error' => [
                'code' => 'invalid_csrf_token',
                'message' => 'Votre session a expiré. Rechargez la page puis réessayez.',
            ],
        ], Response::HTTP_FORBIDDEN));
        $event->allowCustomResponseCode();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 10],
        ];
    }
}
