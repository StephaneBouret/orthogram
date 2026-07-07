<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class SuspendedUserSessionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', -10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User || $user->isAccountActive()) {
            return;
        }

        $request = $event->getRequest();
        $this->tokenStorage->setToken(null);

        if ($request->hasSession()) {
            $session = $request->getSession();
            $session->invalidate();

            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add(
                    'warning',
                    'Votre compte n\'est plus actif. Vous avez été déconnecté.'
                );
            }
        }

        $response = new RedirectResponse($this->urlGenerator->generate('app_login'));
        $response->headers->clearCookie('REMEMBERME', '/');

        $event->setResponse($response);
    }
}
