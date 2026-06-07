<?php

namespace App\Services;

use App\Entity\User;
use App\Entity\UserDevice;
use App\Exception\TooManyActiveDevicesException;
use App\Repository\UserDeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

class DeviceService
{
    public const COOKIE_NAME = 'device_uuid';
    public const MAX_ACTIVE_DEVICES = 2;

    public function __construct(
        private readonly UserDeviceRepository $userDeviceRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function registerOrRefreshDevice(User $user, Request $request): UserDevice
    {
        $deviceUuid = $this->getOrCreateDeviceUuid($request);
        $device = $this->userDeviceRepository->findOneByUuidForUser($deviceUuid, $user);
        $ip = $request->getClientIp();

        if (!$device instanceof UserDevice) {
            $this->assertCanAddActiveDevice($user);

            $device = (new UserDevice())
                ->setUser($user)
                ->setDeviceUuid($deviceUuid)
                ->setFirstIp($ip);

            $this->entityManager->persist($device);
        } elseif ($device->isRevoked()) {
            $this->assertCanAddActiveDevice($user);
            $device->reactivate();

            if (null === $device->getFirstIp()) {
                $device->setFirstIp($ip);
            }
        }

        $this->applyRequestMetadata($device, $request);
        $device->markUsed($ip);

        $this->entityManager->flush();

        return $device;
    }

    public function revokeDevice(UserDevice $device): void
    {
        $device->revoke();
        $device->getUser()?->invalidateTrustedDevices();

        $this->entityManager->flush();
    }

    public function reactivateDevice(UserDevice $device): void
    {
        $device->reactivate();
        $device->getUser()?->invalidateTrustedDevices();

        $this->entityManager->flush();
    }

    public function getOrCreateDeviceUuid(Request $request): string
    {
        return $this->getDeviceUuidFromCookie($request) ?? Uuid::v4()->toRfc4122();
    }

    public function getDeviceUuidFromCookie(Request $request): ?string
    {
        $deviceUuid = trim((string) $request->cookies->get(self::COOKIE_NAME, ''));

        if ('' === $deviceUuid || !Uuid::isValid($deviceUuid)) {
            return null;
        }

        return strtolower($deviceUuid);
    }

    public function createDeviceCookie(string $deviceUuid, Request $request): Cookie
    {
        return Cookie::create(
            name: self::COOKIE_NAME,
            value: $deviceUuid,
            expire: new \DateTimeImmutable('+1 year'),
            path: '/',
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    private function assertCanAddActiveDevice(User $user): void
    {
        if ($this->userDeviceRepository->countActiveForUser($user) >= self::MAX_ACTIVE_DEVICES) {
            throw new TooManyActiveDevicesException(self::MAX_ACTIVE_DEVICES);
        }
    }

    private function applyRequestMetadata(UserDevice $device, Request $request): void
    {
        $userAgent = (string) $request->headers->get('User-Agent', '');

        $device
            ->setUserAgentHash('' !== $userAgent ? hash('sha256', $userAgent) : null)
            ->setDeviceType($this->detectDeviceType($userAgent))
            ->setBrowser($this->detectBrowser($userAgent))
            ->setPlatform($this->detectPlatform($userAgent, $request));
    }

    private function detectDeviceType(string $userAgent): string
    {
        if (preg_match('/ipad|tablet|android(?!.*mobile)/i', $userAgent)) {
            return 'tablet';
        }

        if (preg_match('/mobile|iphone|ipod|android.*mobile|windows phone/i', $userAgent)) {
            return 'mobile';
        }

        return 'desktop';
    }

    private function detectBrowser(string $userAgent): string
    {
        return match (true) {
            1 === preg_match('/Edg\//i', $userAgent) => 'Edge',
            1 === preg_match('/OPR\/|Opera/i', $userAgent) => 'Opera',
            1 === preg_match('/SamsungBrowser/i', $userAgent) => 'Samsung Internet',
            1 === preg_match('/Firefox\/|FxiOS\//i', $userAgent) => 'Firefox',
            1 === preg_match('/Chrome\/|CriOS\//i', $userAgent) => 'Chrome',
            1 === preg_match('/Safari\//i', $userAgent) => 'Safari',
            1 === preg_match('/MSIE|Trident/i', $userAgent) => 'Internet Explorer',
            default => 'Inconnu',
        };
    }

    private function detectPlatform(string $userAgent, Request $request): string
    {
        $clientHintPlatform = trim((string) $request->headers->get('Sec-CH-UA-Platform', ''), '" ');

        if ('' !== $clientHintPlatform) {
            return $this->truncate($clientHintPlatform, 100);
        }

        return match (true) {
            1 === preg_match('/Android/i', $userAgent) => 'Android',
            1 === preg_match('/iPhone|iPad|iPod/i', $userAgent) => 'iOS',
            1 === preg_match('/Windows/i', $userAgent) => 'Windows',
            1 === preg_match('/Macintosh|Mac OS X/i', $userAgent) => 'macOS',
            1 === preg_match('/CrOS/i', $userAgent) => 'Chrome OS',
            1 === preg_match('/Linux/i', $userAgent) => 'Linux',
            default => 'Inconnu',
        };
    }

    private function truncate(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}
