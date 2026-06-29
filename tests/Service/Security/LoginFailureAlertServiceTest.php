<?php

declare(strict_types=1);

namespace App\Tests\Service\Security;

use App\Entity\LoginFailureAlert;
use App\Entity\User;
use App\Enum\UserAccountStatus;
use App\Repository\LoginFailureAlertRepository;
use App\Repository\LoginFailureLogRepository;
use App\Service\Security\LoginFailureAlertService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

final class LoginFailureAlertServiceTest extends TestCase
{
    public function testItSendsEmailAndCreatesAlertWhenThresholdIsReached(): void
    {
        $user = $this->createUser();
        $sentEmail = null;
        $persistedAlert = null;

        $service = new LoginFailureAlertService(
            $this->createFailureLogRepositoryReturning(30),
            $this->createAlertRepositoryReturning(false),
            $this->createMailerCapturingEmail($sentEmail, self::once()),
            $this->createEntityManagerCapturingAlert($persistedAlert, self::once(), self::once()),
            'no-reply@orthogram.local',
        );

        $service->notifyIfNeeded($user, '203.0.113.42');

        self::assertInstanceOf(TemplatedEmail::class, $sentEmail);
        self::assertSame('Tentatives de connexion détectées sur votre compte', $sentEmail->getSubject());
        self::assertSame('existing@example.test', $sentEmail->getTo()[0]->getAddress());
        self::assertSame('no-reply@orthogram.local', $sentEmail->getFrom()[0]->getAddress());

        self::assertInstanceOf(LoginFailureAlert::class, $persistedAlert);
        self::assertSame($user, $persistedAlert->getUser());
        self::assertSame(30, $persistedAlert->getFailureCount());
        self::assertSame('203.0.113.42', $persistedAlert->getIpAddress());
        self::assertInstanceOf(\DateTimeImmutable::class, $persistedAlert->getSentAt());
        self::assertTrue($user->isAccountActive());
    }

    public function testItDoesNotSendEmailBeforeThreshold(): void
    {
        $sentEmail = null;
        $persistedAlert = null;

        $alertRepository = $this->createMock(LoginFailureAlertRepository::class);
        $alertRepository
            ->expects(self::never())
            ->method('hasRecentAlertForUser');

        $service = new LoginFailureAlertService(
            $this->createFailureLogRepositoryReturning(29),
            $alertRepository,
            $this->createMailerCapturingEmail($sentEmail, self::never()),
            $this->createEntityManagerCapturingAlert($persistedAlert, self::never(), self::never()),
            'no-reply@orthogram.local',
        );

        $service->notifyIfNeeded($this->createUser(), '203.0.113.42');

        self::assertNull($sentEmail);
        self::assertNull($persistedAlert);
    }

    public function testItDoesNotSendEmailTwiceWithinSameWindow(): void
    {
        $sentEmail = null;
        $persistedAlert = null;

        $service = new LoginFailureAlertService(
            $this->createFailureLogRepositoryReturning(30),
            $this->createAlertRepositoryReturning(true),
            $this->createMailerCapturingEmail($sentEmail, self::never()),
            $this->createEntityManagerCapturingAlert($persistedAlert, self::never(), self::never()),
            'no-reply@orthogram.local',
        );

        $service->notifyIfNeeded($this->createUser(), '203.0.113.42');

        self::assertNull($sentEmail);
        self::assertNull($persistedAlert);
    }

    public function testItDoesNotSuspendTheUserAccount(): void
    {
        $user = $this->createUser();
        $user->setAccountStatus(UserAccountStatus::ACTIVE);
        $sentEmail = null;
        $persistedAlert = null;

        $service = new LoginFailureAlertService(
            $this->createFailureLogRepositoryReturning(30),
            $this->createAlertRepositoryReturning(false),
            $this->createMailerCapturingEmail($sentEmail, self::once()),
            $this->createEntityManagerCapturingAlert($persistedAlert, self::once(), self::once()),
            'no-reply@orthogram.local',
        );

        $service->notifyIfNeeded($user);

        self::assertSame(UserAccountStatus::ACTIVE, $user->getAccountStatus());
        self::assertTrue($user->isAccountActive());
    }

    private function createUser(): User
    {
        return (new User())
            ->setEmail('existing@example.test')
            ->setFirstname('Camille')
            ->setLastname('Martin')
        ;
    }

    private function createFailureLogRepositoryReturning(int $failureCount): LoginFailureLogRepository
    {
        $repository = $this->createMock(LoginFailureLogRepository::class);
        $repository
            ->expects(self::once())
            ->method('countRecentFailuresForUser')
            ->willReturn($failureCount);

        return $repository;
    }

    private function createAlertRepositoryReturning(bool $hasRecentAlert): LoginFailureAlertRepository
    {
        $repository = $this->createMock(LoginFailureAlertRepository::class);
        $repository
            ->expects(self::once())
            ->method('hasRecentAlertForUser')
            ->willReturn($hasRecentAlert);

        return $repository;
    }

    private function createMailerCapturingEmail(?RawMessage &$sentEmail, \PHPUnit\Framework\MockObject\Rule\InvocationOrder $expects): MailerInterface
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer
            ->expects($expects)
            ->method('send')
            ->willReturnCallback(static function (RawMessage $message) use (&$sentEmail): void {
                $sentEmail = $message;
            });

        return $mailer;
    }

    private function createEntityManagerCapturingAlert(
        ?LoginFailureAlert &$persistedAlert,
        \PHPUnit\Framework\MockObject\Rule\InvocationOrder $persistExpects,
        \PHPUnit\Framework\MockObject\Rule\InvocationOrder $flushExpects,
    ): EntityManagerInterface {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects($persistExpects)
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persistedAlert): void {
                if ($entity instanceof LoginFailureAlert) {
                    $persistedAlert = $entity;
                }
            });
        $entityManager
            ->expects($flushExpects)
            ->method('flush');

        return $entityManager;
    }
}
