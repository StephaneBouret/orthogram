<?php

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testEmailAuthCodeIsStoredWithExpiration(): void
    {
        $user = new User();

        $user->setEmailAuthCode('123456');

        self::assertSame('123456', $user->getEmailAuthCode());
        self::assertInstanceOf(\DateTimeImmutable::class, $this->getPrivateProperty($user, 'authCodeExpiresAt'));
    }

    public function testExpiredEmailAuthCodeIsNotReturned(): void
    {
        $user = new User();
        $user->setEmailAuthCode('123456');
        $this->setPrivateProperty($user, 'authCodeExpiresAt', new \DateTimeImmutable('-1 minute'));

        self::assertNull($user->getEmailAuthCode());
    }

    public function testEmailAuthCodeCanBeCleared(): void
    {
        $user = new User();
        $user->setEmailAuthCode('123456');

        $user->clearEmailAuthCode();

        self::assertNull($user->getEmailAuthCode());
        self::assertNull($this->getPrivateProperty($user, 'authCode'));
        self::assertNull($this->getPrivateProperty($user, 'authCodeExpiresAt'));
    }

    public function testTrustedDevicesCanBeInvalidated(): void
    {
        $user = new User();

        self::assertSame(0, $user->getTrustedTokenVersion());

        $user->invalidateTrustedDevices();

        self::assertSame(1, $user->getTrustedTokenVersion());
    }

    private function getPrivateProperty(User $user, string $property): mixed
    {
        $reflectionProperty = new \ReflectionProperty($user, $property);

        return $reflectionProperty->getValue($user);
    }

    private function setPrivateProperty(User $user, string $property, mixed $value): void
    {
        $reflectionProperty = new \ReflectionProperty($user, $property);
        $reflectionProperty->setValue($user, $value);
    }
}
