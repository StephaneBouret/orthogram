<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Exception\TooManyActiveDevicesException;
use App\Services\DeviceService;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class DeviceLoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly DeviceService $deviceService,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => ['onLoginSuccess', -128],
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ('main' !== $event->getFirewallName()) {
            return;
        }

        if ($event->getAuthenticatedToken() instanceof TwoFactorTokenInterface) {
            return;
        }

        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        $request = $event->getRequest();

        try {
            $device = $this->deviceService->registerOrRefreshDevice($user, $request);
        } catch (TooManyActiveDevicesException) {
            $this->tokenStorage->setToken(null);

            if ($request->hasSession()) {
                $request->getSession()->invalidate();
            }

            $response = new RedirectResponse($this->urlGenerator->generate('app_login', [
                'device_limit' => 1,
            ]));

            $response->headers->clearCookie(DeviceService::COOKIE_NAME);
            $response->headers->clearCookie('REMEMBERME');
            $response->headers->clearCookie('trusted_device');

            $event->setResponse($response);

            return;
        }

        $response = $event->getResponse() ?? new RedirectResponse($this->urlGenerator->generate('app_home'));
        $response->headers->setCookie($this->deviceService->createDeviceCookie((string) $device->getDeviceUuid(), $request));

        $event->setResponse($response);
    }
}
