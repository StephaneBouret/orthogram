<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class LoginRateLimiterSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RateLimiterFactory $loginGlobalLimiter,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->attributes->get('_route') !== 'app_login') {
            return;
        }

        if (!$request->isMethod('POST')) {
            return;
        }

        $limiter = $this->loginGlobalLimiter->create($request->getClientIp() ?? 'unknown');
        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(
                $limit->getRetryAfter()->getTimestamp() - time(),
                'Trop de tentatives de connexion. Veuillez réessayer dans quelques instants.',
            );
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }
}
