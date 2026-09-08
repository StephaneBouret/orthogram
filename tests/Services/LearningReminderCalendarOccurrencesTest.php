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
use Sabre\VObject\Reader;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class LearningReminderCalendarOccurrencesTest extends TestCase
{
    /**
     * @param list<int> $days
     *
     * @return array{ics: string, googleUrl: ?string, expiresAt: string, notice: string}
     */
    private function prepare(string $frequency, string $time, array $days, string $reference): array
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('https://orthogram.test/entrainement');

        return (new LearningReminderCalendarService(new LearningReminderNextRunCalculator(), new LearningReminderIcalendarWriter(), $router))->prepare(new LearningReminderPayload($frequency, $time, $days, null, 'Europe/Paris'), new \DateTimeImmutable($reference));
    }

    /** @param list<int> $days
     * @param list<string> $firstThree
     */
    #[DataProvider('ordinarySeries')]
    public function testOrdinarySeriesFullyExpanded(string $frequency, array $days, array $firstThree, int $count, string $last): void
    {
        $prepared = $this->prepare($frequency, '08:30', $days, '2026-09-07T06:00:00Z');
        self::assertNull($prepared['googleUrl']);
        self::assertStringContainsString('07/09/2031 à 08:30:00 +02:00 inclus', $prepared['notice']);
        $ics = $prepared['ics'];
        self::assertStringNotContainsString('EXDATE', $ics);
        self::assertStringNotContainsString('RDATE', $ics);
        self::assertStringNotContainsString('RECURRENCE-ID', $ics);
        self::assertStringContainsString('UNTIL=20310907T063000Z', $ics);
        $result = (new IcalendarOracle())->expand($ics);
        self::assertSame('ok', $result['status']);
        $rows = $result['occurrences'];
        self::assertCount($count, $rows);
        self::assertSame($firstThree, array_slice(array_column($rows, 'start'), 0, 3));
        self::assertSame($last, $rows[array_key_last($rows)]['start']);
        $starts = array_column($rows, 'startTimestamp');
        self::assertCount(\count($starts), array_unique($starts));
        foreach ($rows as $row) {
            self::assertSame(900, $row['endTimestamp'] - $row['startTimestamp']);
            self::assertLessThanOrEqual('2031-09-07T06:30:00Z', $row['start']);
            $local = (new \DateTimeImmutable($row['start']))->setTimezone(new \DateTimeZone('Europe/Paris'));
            self::assertSame('08:30', $local->format('H:i'));
            if ([] !== $days) {
                self::assertContains((int) $local->format('N'), $days);
            }
        }
        // Fixed points on both sides of later seasonal transitions.
        if ('daily' === $frequency) {
            self::assertContains('2026-10-24T06:30:00Z', array_column($rows, 'start'));
            self::assertContains('2026-10-25T07:30:00Z', array_column($rows, 'start'));
            self::assertContains('2027-03-27T07:30:00Z', array_column($rows, 'start'));
            self::assertContains('2027-03-28T06:30:00Z', array_column($rows, 'start'));
        }
    }

    /** @return iterable<string, array{string, list<int>, list<string>, int, string}> */
    public static function ordinarySeries(): iterable
    {
        yield 'daily' => ['daily', [], ['2026-09-07T06:30:00Z', '2026-09-08T06:30:00Z', '2026-09-09T06:30:00Z'], 1827, '2031-09-07T06:30:00Z'];
        yield 'weekly' => ['weekly', [1, 4], ['2026-09-07T06:30:00Z', '2026-09-10T06:30:00Z', '2026-09-14T06:30:00Z'], 522, '2031-09-04T06:30:00Z'];
    }

    /** @param list<int> $days */
    #[DataProvider('initialTransitions')]
    public function testProductAmbiguousSecondPassageIsSeparate(string $frequency, array $days, string $reference, string $nominal, string $start, string $end): void
    {
        $prepared = $this->prepare($frequency, '02:30', $days, $reference);
        $ics = $prepared['ics'];
        $calendar = Reader::read($ics);
        self::assertSame([], $calendar->validate());
        $events = array_values($calendar->select('VEVENT'));
        self::assertCount(2, $events);
        self::assertNotSame((string) $events[0]->UID, (string) $events[1]->UID);
        self::assertSame($start, (string) $events[0]->DTSTART);
        self::assertSame($end, (string) $events[0]->DTEND);
        self::assertFalse(isset($events[0]->RRULE));
        $nextDay = 'daily' === $frequency || in_array(1, $days, true) ? '20261026' : '20261101';
        self::assertSame($nextDay.'T023000', (string) $events[1]->DTSTART);
        self::assertSame('Europe/Paris', (string) $events[1]->DTSTART['TZID']);
        self::assertSame('PT15M', (string) $events[1]->DURATION);
        self::assertSame(1, substr_count($ics, 'RRULE:'));
        $rule = 'FREQ='.strtoupper($frequency);
        if ('weekly' === $frequency) {
            $rule .= [7] === $days ? ';BYDAY=SU;WKST=MO' : ';BYDAY=MO,TH,SU;WKST=MO';
        }
        self::assertSame($rule.';UNTIL=20311025T013000Z', (string) $events[1]->RRULE);
        foreach (['EXDATE', 'RDATE', 'RECURRENCE-ID', 'DTSTART;TZID=Europe/Paris:'.$nominal] as $property) {
            self::assertStringNotContainsString($property, $ics);
        }
        self::assertSame(LearningReminderCalendarService::SEPARATE_FIRST_NOTICE, $prepared['separateFirstNotice']);
        foreach ($events as $event) {
            self::assertStringContainsString(LearningReminderCalendarService::SEPARATE_FIRST_NOTICE, (string) $event->DESCRIPTION);
        }
        // Exact product output, no repair. Only the fixed first/next points and
        // bound are asserted; later gap and duration limitations remain separate.
        $rows = (new IcalendarOracle())->expand($ics)['occurrences'];
        self::assertSame('2026-10-25T01:30:00Z', $rows[0]['start']);
        self::assertSame('2026-10-25T01:45:00Z', $rows[0]['end']);
        self::assertSame('20261026' === $nextDay ? '2026-10-26T01:30:00Z' : '2026-11-01T01:30:00Z', $rows[1]['start']);
        self::assertSame('20261026' === $nextDay ? '2026-10-26T01:45:00Z' : '2026-11-01T01:45:00Z', $rows[1]['end']);
        self::assertSame((string) $events[0]->UID, $rows[0]['uid']);
        self::assertSame((string) $events[1]->UID, $rows[1]['uid']);
        foreach (array_slice($rows, 0, 2) as $row) {
            self::assertSame(900, $row['endTimestamp'] - $row['startTimestamp']);
        }
        self::assertNotContains('2026-10-25T00:30:00Z', array_column($rows, 'start'));
        self::assertCount(count($rows), array_unique(array_column($rows, 'start')));
        foreach ($rows as $row) {
            self::assertLessThanOrEqual('2031-10-25T01:30:00Z', $row['start']);
        }
        if ('weekly' === $frequency && [7] === $days) {
            self::assertSame('2031-10-19T00:30:00Z', $rows[array_key_last($rows)]['start']);
        }
    }

    /** @return iterable<string, array{string, list<int>, string, string, string, string}> */
    public static function initialTransitions(): iterable
    {
        yield 'weekly multiple ISO days' => ['weekly', [7, 1, 4], '2026-10-25T01:00:00Z', '20261025T023000', '20261025T013000Z', '20261025T014500Z'];
        foreach (['daily' => [], 'weekly' => [7]] as $frequency => $days) {
            yield $frequency.' autumn' => [$frequency, $days, '2026-10-24T22:00:00Z', '20261025T023000', '20261025T013000Z', '20261025T014500Z'];
            yield $frequency.' between ambiguous instants' => [$frequency, $days, '2026-10-25T01:00:00Z', '20261025T023000', '20261025T013000Z', '20261025T014500Z'];
        }
    }

    public function testProductDurationCrossesSpringTransitionWithoutBecomingSeventyFiveMinutes(): void
    {
        $ics = $this->prepare('daily', '01:55', [], '2026-03-28T23:00:00Z')['ics'];
        $rows = (new IcalendarOracle())->expand($ics)['occurrences'];
        self::assertSame('2026-03-29T00:55:00Z', $rows[0]['start']);
        self::assertSame('2026-03-29T01:10:00Z', $rows[0]['end']);
        self::assertSame(900, $rows[0]['endTimestamp'] - $rows[0]['startTimestamp']);
    }

    public static function splitSeries(): iterable
    {
        yield 'daily A' => ['daily', [], '20260330T023000', [
            '2026-03-29T01:30:00Z', '2026-03-30T00:30:00Z', '2026-03-31T00:30:00Z',
            '2026-04-01T00:30:00Z', '2026-04-02T00:30:00Z', '2026-04-03T00:30:00Z',
            '2026-04-04T00:30:00Z', '2026-04-05T00:30:00Z',
        ], '2031-03-29T01:30:00Z'];
        yield 'weekly Sunday' => ['weekly', [7], '20260405T023000', ['2026-03-29T01:30:00Z', '2026-04-05T00:30:00Z', '2026-04-12T00:30:00Z'], '2031-03-23T01:30:00Z'];
        yield 'weekly multiple ISO days' => ['weekly', [7, 1, 4], '20260330T023000', ['2026-03-29T01:30:00Z', '2026-03-30T00:30:00Z', '2026-04-02T00:30:00Z'], '2031-03-27T01:30:00Z'];
    }

    #[DataProvider('splitSeries')]
    public function testInitialGapIsSeparateAndNominalSeriesResumes(string $frequency, array $days, string $masterStart, array $firstStarts, string $lastStart): void
    {
        $prepared = $this->prepare($frequency, '02:30', $days, '2026-03-28T23:00:00Z');
        $calendar = Reader::read($prepared['ics']);
        self::assertSame([], $calendar->validate());
        $events = array_values($calendar->select('VEVENT'));
        self::assertCount(2, $events);
        self::assertNotSame((string) $events[0]->UID, (string) $events[1]->UID);
        self::assertSame('20260329T013000Z', (string) $events[0]->DTSTART);
        self::assertSame('20260329T014500Z', (string) $events[0]->DTEND);
        self::assertFalse(isset($events[0]->RRULE));
        self::assertSame($masterStart, (string) $events[1]->DTSTART);
        self::assertSame('Europe/Paris', (string) $events[1]->DTSTART['TZID']);
        self::assertSame('PT15M', (string) $events[1]->DURATION);
        self::assertStringContainsString('UNTIL=20310329T013000Z', (string) $events[1]->RRULE);
        foreach (['EXDATE', 'RDATE', 'RECURRENCE-ID'] as $property) {
            self::assertStringNotContainsString($property, $prepared['ics']);
        }
        $rows = (new IcalendarOracle())->expand($prepared['ics'])['occurrences'];
        self::assertSame($firstStarts, array_slice(array_column($rows, 'start'), 0, count($firstStarts)));
        self::assertSame((string) $events[0]->UID, $rows[0]['uid']);
        self::assertSame((string) $events[1]->UID, $rows[1]['uid']);
        self::assertSame('2026-03-29T01:45:00Z', $rows[0]['end']);
        // Only these fixed, ordinary following points are product assertions.
        // Later missing hours remain a sabre characterization, not a conformance pass.
        foreach (array_slice($rows, 0, count($firstStarts)) as $row) {
            self::assertSame(900, $row['endTimestamp'] - $row['startTimestamp']);
        }
        self::assertSame($lastStart, $rows[array_key_last($rows)]['start']);
        if ('daily' === $frequency) {
            self::assertSame('2031-03-29T01:45:00Z', $rows[array_key_last($rows)]['end']);
        }
        self::assertCount(count($rows), array_unique(array_column($rows, 'start')));
        foreach ($rows as $row) {
            self::assertLessThanOrEqual('2031-03-29T01:30:00Z', $row['start']);
            if ([] !== $days) {
                self::assertContains((int) (new \DateTimeImmutable($row['start']))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('N'), $days);
            }
        }
    }

    public function testProductCaseDRemainsNominalWithAccurateMinuteDuration(): void
    {
        $prepared = $this->prepare('daily', '02:55', [], '2026-10-24T00:00:00Z');
        $events = array_values(Reader::read($prepared['ics'])->select('VEVENT'));
        self::assertCount(1, $events);
        self::assertSame('20261024T025500', (string) $events[0]->DTSTART);
        self::assertSame('PT15M', (string) $events[0]->DURATION);
        self::assertNull($prepared['separateFirstNotice']);
        // This structure does not validate the 25 October occurrence in a client.
        // Fixed product expectation: 00:55Z -> 01:10Z. Sabre's 02:10Z end
        // is tested exclusively in IcalendarOracleTest on the minimal fixture.
    }
}
