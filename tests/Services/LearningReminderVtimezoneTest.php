<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Dto\LearningReminderPayload;
use App\Services\LearningReminderCalendarService;
use App\Services\LearningReminderIcalendarWriter;
use App\Services\LearningReminderNextRunCalculator;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Structural assertions on exact writer output; not independent timezone execution. */
final class LearningReminderVtimezoneTest extends TestCase
{
    public function testBCoverageRemainsBasedOnOriginalFirstOccurrence(): void
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('https://orthogram.test/entrainement');
        $prepared = (new LearningReminderCalendarService(new LearningReminderNextRunCalculator(), new LearningReminderIcalendarWriter(), $router))->prepare(new LearningReminderPayload('weekly', '02:30', [7], null, 'Europe/Paris'), new \DateTimeImmutable('2026-10-25T01:00:00Z'));
        $calendar = Reader::read($prepared['ics']);
        self::assertCount(1, $calendar->select('VTIMEZONE'));
        self::assertSame('20311025T013000Z', (string) $calendar->{'X-ORTHOGRAM-UNTIL'});
        $zone = $calendar->select('VTIMEZONE')[0];
        self::assertSame('20311025T014500Z', (string) $zone->{'X-ORTHOGRAM-COVERAGE-END'});
        self::assertStringContainsString("DTSTART:20261025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100", $prepared['ics']);
        self::assertStringContainsString("DTSTART:20310330T020000\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200", $prepared['ics']);
        // The synthetic opening observance must also keep its original date.
        $observances = array_values(iterator_to_array($zone->getComponents()));
        self::assertSame('20251020T033000', (string) $observances[0]->DTSTART);
    }

    public function testSplitCoverageStillUsesOriginalFirstAndUntil(): void
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('https://orthogram.test/entrainement');
        $prepared = (new LearningReminderCalendarService(new LearningReminderNextRunCalculator(), new LearningReminderIcalendarWriter(), $router))->prepare(new LearningReminderPayload('daily', '02:30', [], null, 'Europe/Paris'), new \DateTimeImmutable('2026-03-28T23:00:00Z'));
        $calendar = Reader::read($prepared['ics']);
        self::assertCount(1, $calendar->select('VTIMEZONE'));
        $zone = $calendar->select('VTIMEZONE')[0];
        self::assertSame('20310329T014500Z', (string) $zone->{'X-ORTHOGRAM-COVERAGE-END'});
        self::assertSame('20310329T013000Z', (string) $calendar->{'X-ORTHOGRAM-UNTIL'});
        self::assertStringContainsString("DTSTART:20260329T020000\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200", $prepared['ics']);
        self::assertStringContainsString("DTSTART:20301027T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100", $prepared['ics']);
    }

    public function testParisObservancesCoverTheWholeFiveYearExport(): void
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('https://orthogram.test/entrainement');
        $prepared = (new LearningReminderCalendarService(new LearningReminderNextRunCalculator(), new LearningReminderIcalendarWriter(), $router))->prepare(new LearningReminderPayload('daily', '08:30', [], null, 'Europe/Paris'), new \DateTimeImmutable('2026-09-07T06:00:00Z'));
        $calendar = Reader::read($prepared['ics']);
        self::assertInstanceOf(VCalendar::class, $calendar);
        self::assertSame([], $calendar->validate());
        self::assertCount(1, $calendar->select('VTIMEZONE'));
        $zone = $calendar->select('VTIMEZONE')[0];
        self::assertSame('Europe/Paris', (string) $zone->TZID);
        self::assertSame('20310907T064500Z', (string) $zone->{'X-ORTHOGRAM-COVERAGE-END'});
        self::assertSame('20310907T063000Z', (string) $calendar->select('X-ORTHOGRAM-UNTIL')[0]);
        $actual = [];
        foreach ($zone->getComponents() as $observance) {
            $actual[] = [$observance->name, (string) $observance->DTSTART, (string) $observance->TZOFFSETFROM, (string) $observance->TZOFFSETTO];
            self::assertFalse(isset($observance->RRULE), 'A finite transition list is not advertised as an infinite timezone.');
        }
        $expected = [
            ['DAYLIGHT', '20250902T083000', '+0200', '+0200'],
            ['STANDARD', '20251026T030000', '+0200', '+0100'],
            ['DAYLIGHT', '20260329T020000', '+0100', '+0200'],
            ['STANDARD', '20261025T030000', '+0200', '+0100'],
            ['DAYLIGHT', '20270328T020000', '+0100', '+0200'],
            ['STANDARD', '20271031T030000', '+0200', '+0100'],
            ['DAYLIGHT', '20280326T020000', '+0100', '+0200'],
            ['STANDARD', '20281029T030000', '+0200', '+0100'],
            ['DAYLIGHT', '20290325T020000', '+0100', '+0200'],
            ['STANDARD', '20291028T030000', '+0200', '+0100'],
            ['DAYLIGHT', '20300331T020000', '+0100', '+0200'],
            ['STANDARD', '20301027T030000', '+0200', '+0100'],
            ['DAYLIGHT', '20310330T020000', '+0100', '+0200'],
        ];
        // sabre groups child components by name. Observance order is not semantic;
        // check every fixed tuple and the total, without removing duplicates.
        self::assertCount(\count($expected), $actual);
        foreach ($expected as $observance) {
            self::assertContains($observance, $actual);
        }
    }

    public function testTransitionBetweenUntilAndLastEndIsStillEmbedded(): void
    {
        // Deliberately short writer fixture isolates the coverage boundary.
        $first = new \DateTimeImmutable('2026-03-28T00:55:00Z');
        $ics = (new LearningReminderIcalendarWriter())->write(new LearningReminderPayload('daily', '01:55', [], null, 'Europe/Paris'), '20260328T015500', $first, new \DateTimeImmutable('2026-03-29T00:55:00Z'), $first, 'https://orthogram.test', 'Test de couverture');
        $calendar = Reader::read($ics);
        self::assertInstanceOf(VCalendar::class, $calendar);
        self::assertSame('20260329T011000Z', (string) $calendar->select('VTIMEZONE')[0]->{'X-ORTHOGRAM-COVERAGE-END'});
        self::assertStringContainsString("DTSTART:20260329T020000\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200", $ics);
    }

    public function testFixedOffsetZoneHasAnInitialObservance(): void
    {
        $first = new \DateTimeImmutable('2026-09-07T08:30:00Z');
        $ics = (new LearningReminderIcalendarWriter())->write(new LearningReminderPayload('daily', '08:30', [], null, 'UTC'), '20260907T083000', $first, new \DateTimeImmutable('2031-09-07T08:30:00Z'), $first, 'https://orthogram.test', 'Test UTC');
        $calendar = Reader::read($ics);
        self::assertInstanceOf(VCalendar::class, $calendar);
        $zone = $calendar->select('VTIMEZONE')[0];
        self::assertCount(1, $zone->getComponents());
        self::assertSame('+0000', (string) $zone->STANDARD->TZOFFSETFROM);
        self::assertSame('+0000', (string) $zone->STANDARD->TZOFFSETTO);
    }
}
