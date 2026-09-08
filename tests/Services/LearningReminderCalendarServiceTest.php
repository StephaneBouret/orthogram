<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Dto\LearningReminderPayload;
use App\Services\LearningReminderCalendarService;
use App\Services\LearningReminderIcalendarWriter;
use App\Services\LearningReminderNextRunCalculator;
use App\Tests\Support\IcalendarOracle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;

final class LearningReminderCalendarServiceTest extends TestCase
{
    private function service(): LearningReminderCalendarService
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::once())->method('generate')->with('app_user_training', [], UrlGeneratorInterface::ABSOLUTE_URL)->willReturn('https://orthogram.test/entrainement?source=été&action=apprendre');

        return new LearningReminderCalendarService(new LearningReminderNextRunCalculator(), new LearningReminderIcalendarWriter(), $router);
    }

    public function testOnceGoogleAndIcsHaveTheSameFutureQuarterHour(): void
    {
        $result = $this->service()->prepare(new LearningReminderPayload('once', '08:30', [], '2026-09-07', 'Europe/Paris'), new \DateTimeImmutable('2026-09-07T06:25:00Z'));
        parse_str(parse_url($result['googleUrl'], \PHP_URL_QUERY), $query);
        self::assertSame('TEMPLATE', $query['action']);
        self::assertSame(LearningReminderCalendarService::TITLE, $query['text']);
        self::assertSame('20260907T063000Z/20260907T064500Z', $query['dates']);
        self::assertSame('Europe/Paris', $query['stz']);
        self::assertSame('Europe/Paris', $query['etz']);
        self::assertArrayNotHasKey('ctz', $query);
        self::assertStringContainsString('source=été&action=apprendre', $query['details']);
        self::assertSame('2026-09-07T06:30:00+00:00', $result['expiresAt']);
        self::assertStringNotContainsString('RRULE', $result['ics']);
        $rows = (new IcalendarOracle())->expand($result['ics'])['occurrences'];
        self::assertCount(1, $rows);
        self::assertSame('2026-09-07T06:30:00Z', $rows[0]['start']);
        self::assertSame('2026-09-07T06:45:00Z', $rows[0]['end']);
    }

    public function testPastOnceIsRejected(): void
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $service = new LearningReminderCalendarService(new LearningReminderNextRunCalculator(), new LearningReminderIcalendarWriter(), $router);
        $this->expectException(\InvalidArgumentException::class);
        $service->prepare(new LearningReminderPayload('once', '08:30', [], '2026-09-07', 'Europe/Paris'), new \DateTimeImmutable('2026-09-07T06:30:00Z'));
    }

    public static function splitDeadlines(): iterable
    {
        foreach (['daily', 'weekly'] as $frequency) {
            yield $frequency.' far' => [$frequency, '2026-03-28T23:00:00Z', '2026-03-28T23:05:00+00:00'];
            yield $frequency.' close' => [$frequency, '2026-03-29T01:28:00Z', '2026-03-29T01:30:00+00:00'];
        }
    }

    #[DataProvider('splitDeadlines')]
    public function testSplitRetainsFirstBasedHorizonExpirationAndNotice(string $frequency, string $reference, string $expires): void
    {
        $result = $this->service()->prepare(new LearningReminderPayload($frequency, '02:30', 'weekly' === $frequency ? [7] : [], null, 'Europe/Paris'), new \DateTimeImmutable($reference));
        self::assertSame($expires, $result['expiresAt']);
        self::assertSame(LearningReminderCalendarService::SEPARATE_FIRST_NOTICE, $result['separateFirstNotice']);
        self::assertStringContainsString(LearningReminderCalendarService::SEPARATE_FIRST_NOTICE, $result['notice']);
        self::assertStringContainsString('X-ORTHOGRAM-UNTIL:20310329T013000Z', $result['ics']);
    }

    public static function unsplitNotices(): iterable
    {
        yield 'ordinary' => ['daily', '08:30', [], null, '2026-09-07T06:00:00Z'];
        yield 'once ambiguous' => ['once', '02:30', [], '2026-10-25', '2026-10-25T01:00:00Z'];
        yield 'once gap' => ['once', '02:30', [], '2026-03-29', '2026-03-28T23:00:00Z'];
    }

    #[DataProvider('unsplitNotices')]
    public function testUnsplitExportsHaveNoSeparateNotice(string $frequency, string $time, array $days, ?string $date, string $reference): void
    {
        $result = $this->service()->prepare(new LearningReminderPayload($frequency, $time, $days, $date, 'Europe/Paris'), new \DateTimeImmutable($reference));
        self::assertNull($result['separateFirstNotice']);
        self::assertStringNotContainsString(LearningReminderCalendarService::SEPARATE_FIRST_NOTICE, $result['notice']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function preparationDeadlines(): iterable
    {
        foreach (['once', 'daily', 'weekly'] as $frequency) {
            yield $frequency.' close start' => [$frequency, '2026-09-07T06:28:00Z'];
            yield $frequency.' five minute cap' => [$frequency, '2026-09-07T06:00:00Z'];
        }
    }

    #[DataProvider('preparationDeadlines')]
    public function testExpirationIsFiveMinutesOrFirstOccurrenceForEveryFrequency(string $frequency, string $reference): void
    {
        $result = $this->service()->prepare(
            new LearningReminderPayload($frequency, '08:30', 'weekly' === $frequency ? [1] : [], 'once' === $frequency ? '2026-09-07' : null, 'Europe/Paris'),
            new \DateTimeImmutable($reference),
        );
        self::assertSame('2026-09-07T06:28:00Z' === $reference ? '2026-09-07T06:30:00+00:00' : '2026-09-07T06:05:00+00:00', $result['expiresAt']);
    }

    /** @return iterable<string, array{bool, string, string}> */
    public static function independentRoutingContexts(): iterable
    {
        foreach ([false, true] as $router) {
            yield ($router ? 'router' : 'generator').' origin' => [$router, 'https://configured.test', 'https://configured.test/ma-formation'];
            yield ($router ? 'router' : 'generator').' base path and port' => [$router, 'https://configured.test:8443/orthogram/base/', 'https://configured.test:8443/orthogram/base/ma-formation'];
        }
    }

    #[DataProvider('independentRoutingContexts')]
    public function testTrainingUrlUsesConfiguredOriginAndBasePathWithoutChangingSharedContext(bool $useRouter, string $defaultUri, string $expected): void
    {
        $routes = new RouteCollection();
        $routes->add('app_user_training', new Route('/ma-formation'));
        $context = RequestContext::fromUri('http://request-host.test:8080/request-base');
        $context->setParameter('request_marker', 'unchanged');
        $originalContext = clone $context;
        if ($useRouter) {
            $loader = $this->createStub(LoaderInterface::class);
            $loader->method('load')->willReturn($routes);
            $shared = new Router($loader, 'test-routes', [], $context);
        } else {
            $shared = new UrlGenerator($routes, $context);
        }
        // Warm the real router's internal generator before constructing the service.
        $requestUrl = $shared->generate('app_user_training', [], UrlGeneratorInterface::ABSOLUTE_URL);
        self::assertSame('http://request-host.test:8080/request-base/ma-formation', $requestUrl);
        $service = new LearningReminderCalendarService(new LearningReminderNextRunCalculator(), new LearningReminderIcalendarWriter(), $shared, $defaultUri);
        $result = $service->prepare(new LearningReminderPayload('once', '08:30', [], '2026-09-07', 'Europe/Paris'), new \DateTimeImmutable('2026-09-07T06:00:00Z'));
        $calendar = Reader::read($result['ics']);
        $event = $calendar->select('VEVENT')[0];
        self::assertSame($expected, (string) $event->URL);
        self::assertStringContainsString($expected, (string) $event->DESCRIPTION);
        parse_str(parse_url($result['googleUrl'], \PHP_URL_QUERY), $query);
        self::assertStringContainsString($expected, $query['details']);
        self::assertStringNotContainsString('request-host.test', $query['details']);
        self::assertSame($context, $shared->getContext());
        self::assertEquals($originalContext, $shared->getContext());
        self::assertSame($requestUrl, $shared->generate('app_user_training', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    public function testWriterEscapesTextAndFoldsUtf8WithoutPropertyInjection(): void
    {
        $payload = new LearningReminderPayload('once', '08:30', [], '2026-09-07', 'Europe/Paris');
        $first = new \DateTimeImmutable('2026-09-07T06:30:00Z');
        $description = str_repeat('Été, entraînement; ', 12)."\\\r\nATTENDEE:intrus@example.test";
        $writer = new LearningReminderIcalendarWriter();
        $ics = $writer->write($payload, '20260907T083000', $first, null, $first, 'https://orthogram.test/entrainement', $description);
        self::assertStringEndsWith("\r\n", $ics);
        self::assertSame(0, preg_match('/(?<!\r)\n|\r(?!\n)/', $ics));
        foreach (explode("\r\n", $ics) as $line) {
            self::assertLessThanOrEqual(75, \strlen($line));
            self::assertTrue(mb_check_encoding($line, 'UTF-8'));
        }
        $calendar = Reader::read($ics);
        self::assertInstanceOf(VCalendar::class, $calendar);
        self::assertSame([], $calendar->validate());
        $event = $calendar->select('VEVENT')[0];
        self::assertSame(str_replace("\r\n", "\n", $description), (string) $event->DESCRIPTION);
        self::assertFalse(isset($event->ATTENDEE));
        self::assertCount(0, $event->select('VALARM'));
        $other = Reader::read($writer->write($payload, '20260907T083000', $first, null, $first, 'https://orthogram.test', $description));
        self::assertInstanceOf(VCalendar::class, $other);
        self::assertNotSame((string) $event->UID, (string) $other->select('VEVENT')[0]->UID);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}@orthogram$/', (string) $event->UID);
    }

    public function testFiveCalendarYearsClampLeapDayAndUseUtc(): void
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $service = new LearningReminderCalendarService(new LearningReminderNextRunCalculator(), new LearningReminderIcalendarWriter(), $router);
        self::assertSame('2033-02-28T07:30:00+00:00', $service->fiveYearLimit(new \DateTimeImmutable('2028-02-29T08:30:00+01:00'))->format(\DateTimeInterface::ATOM));
    }

    public static function ambiguousDeadlines(): iterable
    {
        yield ['2026-10-25T01:00:00Z', '2026-10-25T01:05:00+00:00'];
        yield ['2026-10-25T01:28:00Z', '2026-10-25T01:30:00+00:00'];
    }

    #[DataProvider('ambiguousDeadlines')]
    public function testBRetainsOriginalExpirationAndHorizon(string $reference, string $expiration): void
    {
        $result = $this->service()->prepare(new LearningReminderPayload('weekly', '02:30', [7], null, 'Europe/Paris'), new \DateTimeImmutable($reference));
        self::assertSame($expiration, $result['expiresAt']);
        self::assertStringContainsString('UNTIL=20311025T013000Z', $result['ics']);
        self::assertSame(LearningReminderCalendarService::SEPARATE_FIRST_NOTICE, $result['separateFirstNotice']);
    }

    public static function initialPassages(): iterable
    {
        yield 'ordinary' => ['20260907T083000', '2026-09-07T06:30:00Z', false];
        yield 'A gap' => ['20260329T023000', '2026-03-29T01:30:00Z', true];
        yield 'ambiguous FIRST' => ['20261025T023000', '2026-10-25T00:30:00Z', false];
        yield 'ambiguous SECOND' => ['20261025T023000', '2026-10-25T01:30:00Z', true];
    }

    #[DataProvider('initialPassages')]
    public function testSeparationComparesExplicitUtcPassages(string $nominal, string $instant, bool $separate): void
    {
        $writer = new LearningReminderIcalendarWriter();
        $first = new \DateTimeImmutable($instant);
        self::assertSame($separate, $writer->requiresSeparateFirst($nominal, 'Europe/Paris', $first));
        if (!$separate) {
            $time = substr($nominal, 9, 2).':'.substr($nominal, 11, 2);
            $ics = $writer->write(new LearningReminderPayload('daily', $time, [], null, 'Europe/Paris'), $nominal, $first, $first->modify('+5 years'), $first, 'https://orthogram.test', 'fixture');
            self::assertSame(1, substr_count($ics, 'BEGIN:VEVENT'));
            self::assertStringNotContainsString('RECURRENCE-ID', $ics);
            self::assertStringContainsString('DTSTART;TZID=Europe/Paris:'.$nominal, $ics);
            // Do not let sabre's implicit second-passage choice validate the first.
        }
    }

    public static function incoherentInstants(): iterable
    {
        yield ['20260907T083000', '2026-09-07T07:30:00Z'];
        yield ['20261025T023000', '2026-10-25T02:30:00Z'];
        yield ['20260329T023000', '2026-03-29T00:30:00Z'];
    }

    #[DataProvider('incoherentInstants')]
    public function testIncoherentInitialInstantIsRejected(string $nominal, string $instant): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LearningReminderIcalendarWriter())->requiresSeparateFirst($nominal, 'Europe/Paris', new \DateTimeImmutable($instant));
    }

    public static function invalidAnchors(): iterable
    {
        yield 'next eligible date missing' => ['20260328T023000', '2026-03-28T01:30:00Z', '2031-03-28T01:30:00Z'];
        yield 'next eligible date ambiguous' => ['20261024T023000', '2026-10-24T00:30:00Z', '2031-10-24T00:30:00Z'];
        yield 'not strictly after first' => ['20261101T023000', '2026-11-02T01:30:00Z', '2031-11-01T01:30:00Z'];
        yield 'past until' => ['20261101T023000', '2026-11-01T01:30:00Z', '2026-11-02T01:29:59Z'];
    }

    #[DataProvider('invalidAnchors')]
    public function testNextEligibleCivilDateIsRejectedWithoutSkipping(string $nominal, string $first, string $until): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LearningReminderIcalendarWriter())->nextSeriesNominal(new LearningReminderPayload('daily', '02:30', [], null, 'Europe/Paris'), $nominal, new \DateTimeImmutable($first), new \DateTimeImmutable($until));
    }

    public function testAnchorExactlyAtUntilIsIncluded(): void
    {
        self::assertSame('20261101T023000', (new LearningReminderIcalendarWriter())->nextSeriesNominal(new LearningReminderPayload('weekly', '02:30', [7], null, 'Europe/Paris'), '20261025T023000', new \DateTimeImmutable('2026-10-25T01:30:00Z'), new \DateTimeImmutable('2026-11-01T01:30:00Z')));
    }

    public static function invalidWriterAnchors(): iterable
    {
        yield 'missing split' => [null];
        yield 'ambiguous original day' => ['20261025T023000'];
        yield 'unselected Monday' => ['20261026T023000'];
        yield 'silently skipped Sunday' => ['20261108T023000'];
    }

    #[DataProvider('invalidWriterAnchors')]
    public function testWriterUsesSameSeparationAndAnchorChecks(?string $seriesNominal): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $first = new \DateTimeImmutable('2026-10-25T01:30:00Z');
        (new LearningReminderIcalendarWriter())->write(new LearningReminderPayload('weekly', '02:30', [7], null, 'Europe/Paris'), '20261025T023000', $first, new \DateTimeImmutable('2031-10-25T01:30:00Z'), $first, 'https://orthogram.test', 'fixture', $seriesNominal);
    }
}
