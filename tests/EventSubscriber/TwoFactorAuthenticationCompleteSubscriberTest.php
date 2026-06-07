<?php

namespace App\Tests\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\TwoFactorAuthenticationCompleteSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class TwoFactorAuthenticationCompleteSubscriberTest extends TestCase
{
    public function testItSubscribesToTwoFactorAuthenticationCompleteEvent(): void
    {
        self::assertSame(
            [TwoFactorAuthenticationEvents::COMPLETE => 'clearEmailAuthCode'],
            TwoFactorAuthenticationCompleteSubscriber::getSubscribedEvents(),
        );
    }

    public function testItClearsEmailAuthCodeWhenTwoFactorAuthenticationIsComplete(): void
    {
        $user = new User();
        $user->setEmailAuthCode('123456');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $subscriber = new TwoFactorAuthenticationCompleteSubscriber($entityManager);

        $subscriber->clearEmailAuthCode($this->createEventWithUser($user));

        self::assertNull($user->getEmailAuthCode());
    }

    public function testItIgnoresNonApplicationUsers(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::never())
            ->method('flush');

        $subscriber = new TwoFactorAuthenticationCompleteSubscriber($entityManager);

        $subscriber->clearEmailAuthCode($this->createEventWithUser(new InMemoryUser('user@example.test', null)));
    }

    private function createEventWithUser(mixed $user): TwoFactorAuthenticationEvent
    {
        $token = $this->createStub(TokenInterface::class);
        $token
            ->method('getUser')
            ->willReturn($user);

        return new TwoFactorAuthenticationEvent(Request::create('/2fa_check', 'POST'), $token);
    }
}
