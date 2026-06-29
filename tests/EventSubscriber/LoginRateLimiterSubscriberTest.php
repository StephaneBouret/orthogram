<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\LoginRateLimiterSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class LoginRateLimiterSubscriberTest extends TestCase
{
    public function testItSubscribesToKernelRequest(): void
    {
        self::assertSame(
            [KernelEvents::REQUEST => ['onKernelRequest', 10]],
            LoginRateLimiterSubscriber::getSubscribedEvents(),
        );
    }

    public function testItRejectsLoginPostRequestsWhenLimitIsExceededForSameIp(): void
    {
        $subscriber = new LoginRateLimiterSubscriber($this->createLimiterFactory());

        for ($i = 0; $i < 30; ++$i) {
            $subscriber->onKernelRequest($this->createRequestEvent('POST', 'app_login', '192.0.2.10'));
        }

        $this->expectException(TooManyRequestsHttpException::class);
        $this->expectExceptionMessage('Trop de tentatives de connexion. Veuillez réessayer dans quelques instants.');

        $subscriber->onKernelRequest($this->createRequestEvent('POST', 'app_login', '192.0.2.10'));
    }

    public function testItOnlyConsumesTokensForMainPostLoginRequests(): void
    {
        $subscriber = new LoginRateLimiterSubscriber($this->createLimiterFactory());

        for ($i = 0; $i < 30; ++$i) {
            $subscriber->onKernelRequest($this->createRequestEvent('GET', 'app_login', '192.0.2.20'));
            $subscriber->onKernelRequest($this->createRequestEvent('POST', 'app_home', '192.0.2.20'));
            $subscriber->onKernelRequest($this->createRequestEvent('POST', 'app_login', '192.0.2.20', HttpKernelInterface::SUB_REQUEST));
        }

        for ($i = 0; $i < 30; ++$i) {
            $subscriber->onKernelRequest($this->createRequestEvent('POST', 'app_login', '192.0.2.20'));
        }

        self::expectNotToPerformAssertions();
    }

    private function createLimiterFactory(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            [
                'id' => 'login_global',
                'policy' => 'fixed_window',
                'limit' => 30,
                'interval' => '1 minute',
            ],
            new InMemoryStorage(),
        );
    }

    private function createRequestEvent(
        string $method,
        string $route,
        string $clientIp,
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
    ): RequestEvent {
        $request = Request::create('/login', $method, server: ['REMOTE_ADDR' => $clientIp]);
        $request->attributes->set('_route', $route);

        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            $requestType,
        );
    }
}
