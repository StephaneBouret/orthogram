<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\LoginFailureLog;
use App\Entity\User;
use App\EventSubscriber\LoginFailureSubscriber;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

final class LoginFailureSubscriberTest extends TestCase
{
    public function testItSubscribesToLoginFailureEvent(): void
    {
        self::assertSame(
            [LoginFailureEvent::class => 'onLoginFailure'],
            LoginFailureSubscriber::getSubscribedEvents(),
        );
    }

    public function testItCreatesLoginFailureLogForUnknownUsernameWithoutSensitiveFields(): void
    {
        $persistedLog = null;
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository
            ->method('findOneBy')
            ->willReturn(null);

        $entityManager = $this->createEntityManagerCapturingPersistedLog($persistedLog);
        $subscriber = new LoginFailureSubscriber($entityManager, $userRepository);

        $subscriber->onLoginFailure($this->createLoginFailureEvent(
            username: 'unknown@example.test',
            password: 'SecretPassword123!',
            csrfToken: 'csrf-token-value',
        ));

        self::assertInstanceOf(LoginFailureLog::class, $persistedLog);
        self::assertSame('unknown@example.test', $persistedLog->getUsernameAttempted());
        self::assertSame('203.0.113.10', $persistedLog->getIpAddress());
        self::assertSame('Mozilla/5.0 Test Browser', $persistedLog->getUserAgent());
        self::assertNull($persistedLog->getUser());
        self::assertInstanceOf(\DateTimeImmutable::class, $persistedLog->getOccurredAt());

        self::assertFalse(method_exists($persistedLog, 'getPassword'));
        self::assertStringNotContainsString('SecretPassword123!', $this->getLoggedStringValues($persistedLog));
        self::assertStringNotContainsString('csrf-token-value', $this->getLoggedStringValues($persistedLog));
    }

    public function testItAssociatesExistingUserWithLoginFailureLog(): void
    {
        $user = (new User())->setEmail('existing@example.test');
        $criteriaSeen = null;
        $persistedLog = null;

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository
            ->method('findOneBy')
            ->willReturnCallback(static function (array $criteria) use (&$criteriaSeen, $user): User {
                $criteriaSeen = $criteria;

                return $user;
            });

        $entityManager = $this->createEntityManagerCapturingPersistedLog($persistedLog);
        $subscriber = new LoginFailureSubscriber($entityManager, $userRepository);

        $subscriber->onLoginFailure($this->createLoginFailureEvent(
            username: ' Existing@Example.Test ',
            password: 'SecretPassword123!',
            csrfToken: 'csrf-token-value',
        ));

        self::assertSame(['email' => 'existing@example.test'], $criteriaSeen);
        self::assertInstanceOf(LoginFailureLog::class, $persistedLog);
        self::assertSame(' Existing@Example.Test ', $persistedLog->getUsernameAttempted());
        self::assertSame($user, $persistedLog->getUser());
    }

    public function testItDoesNotModifySymfonyFailureResponse(): void
    {
        $persistedLog = null;
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository
            ->method('findOneBy')
            ->willReturn(null);

        $entityManager = $this->createEntityManagerCapturingPersistedLog($persistedLog);
        $subscriber = new LoginFailureSubscriber($entityManager, $userRepository);
        $event = $this->createLoginFailureEvent(
            username: 'unknown@example.test',
            password: 'SecretPassword123!',
            csrfToken: 'csrf-token-value',
        );
        $response = $event->getResponse();

        $subscriber->onLoginFailure($event);

        self::assertSame($response, $event->getResponse());
    }

    private function createEntityManagerCapturingPersistedLog(?LoginFailureLog &$persistedLog): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persistedLog): void {
                if ($entity instanceof LoginFailureLog) {
                    $persistedLog = $entity;
                }
            });
        $entityManager
            ->expects(self::once())
            ->method('flush');

        return $entityManager;
    }

    private function createLoginFailureEvent(string $username, string $password, string $csrfToken): LoginFailureEvent
    {
        $request = Request::create(
            uri: '/login',
            method: 'POST',
            parameters: [
                '_username' => $username,
                '_password' => $password,
                '_csrf_token' => $csrfToken,
            ],
            server: [
                'REMOTE_ADDR' => '203.0.113.10',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 Test Browser',
            ],
        );

        return new LoginFailureEvent(
            new BadCredentialsException(),
            $this->createStub(AuthenticatorInterface::class),
            $request,
            null,
            'main',
        );
    }

    private function getLoggedStringValues(LoginFailureLog $loginFailureLog): string
    {
        return implode(' ', [
            $loginFailureLog->getUsernameAttempted(),
            $loginFailureLog->getIpAddress() ?? '',
            $loginFailureLog->getUserAgent() ?? '',
        ]);
    }
}
