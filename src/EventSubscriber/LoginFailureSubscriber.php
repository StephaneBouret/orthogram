<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\LoginFailureLog;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Security\LoginFailureAlertService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

final readonly class LoginFailureSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private LoginFailureAlertService $loginFailureAlertService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginFailureEvent::class => 'onLoginFailure',
        ];
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if ('main' !== $event->getFirewallName()) {
            return;
        }

        try {
            $this->logFailure($event);
        } catch (\Throwable) {
            return;
        }
    }

    private function logFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        $usernameAttempted = $this->extractUsernameAttempted($event);
        $user = $this->findUser($usernameAttempted);

        $loginFailureLog = (new LoginFailureLog($usernameAttempted))
            ->setIpAddress($request->getClientIp())
            ->setUserAgent($request->headers->get('User-Agent'))
            ->setUser($user)
        ;

        $this->entityManager->persist($loginFailureLog);
        $this->entityManager->flush();

        if ($user instanceof User) {
            $this->loginFailureAlertService->notifyIfNeeded($user, $loginFailureLog->getIpAddress());
        }
    }

    private function extractUsernameAttempted(LoginFailureEvent $event): string
    {
        $usernameAttempted = $event->getRequest()->request->get('_username');

        if (is_scalar($usernameAttempted)) {
            return (string) $usernameAttempted;
        }

        $userBadge = $event->getPassport()?->getBadge(UserBadge::class);

        if ($userBadge instanceof UserBadge) {
            return $userBadge->getUserIdentifier();
        }

        return '';
    }

    private function findUser(string $usernameAttempted): ?User
    {
        if ('' === $usernameAttempted) {
            return null;
        }

        return $this->userRepository->findOneBy([
            'email' => mb_strtolower(trim($usernameAttempted)),
        ]);
    }
}
