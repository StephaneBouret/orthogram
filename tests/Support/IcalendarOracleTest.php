<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Recur\MaxInstancesExceededException;
use Sabre\VObject\Settings;

/** Fixed minimal fixtures characterize sabre 5.0.0, not Orthogram conformance. */
final class IcalendarOracleTest extends TestCase
{
    private function fixture(string $properties, string $extra = ''): string
    {
        return str_replace("\n", "\r\n", "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Oracle characterization//FR\nBEGIN:VEVENT\nUID:fixture@test\nDTSTAMP:20260901T000000Z\n".$properties."\nEND:VEVENT\n".$extra."END:VCALENDAR\n");
    }

    public function testOldExdateRdateFixtureLosesFirstOccurrence(): void
    {
        $ics = $this->fixture("DTSTART;TZID=Europe/Paris:20260907T083000\nDURATION:PT15M\nRRULE:FREQ=DAILY;COUNT=3\nEXDATE;TZID=Europe/Paris:20260907T083000\nRDATE:20260907T063000Z");
        $result = (new IcalendarOracle())->expand($ics);
        self::assertSame('no_instances', $result['status']);
        self::assertNotContains('2026-09-07T06:30:00Z', array_column($result['occurrences'], 'start'));
        // RFC expected remaining dates: September 8 and 9. sabre ignores RRULE
        // when RDATE is present, so this is not a full expansion of that fixture.
        self::assertSame([], $result['occurrences']);
    }

    public function testEmbeddedTimezoneOffsetsAreIgnoredForKnownIanaIdentifier(): void
    {
        $zone = "BEGIN:VTIMEZONE\nTZID:Europe/Paris\nBEGIN:STANDARD\nDTSTART:20250101T000000\nTZOFFSETFROM:+0100\nTZOFFSETTO:+0100\nEND:STANDARD\nEND:VTIMEZONE\n";
        $ics = $this->fixture("DTSTART;TZID=Europe/Paris:20260907T083000\nDURATION:PT15M\nRRULE:FREQ=DAILY;COUNT=2", $zone);
        $oracle = new IcalendarOracle();
        $original = $oracle->expand($ics);
        $changed = $oracle->expand(str_replace('+0100', '+0900', $ics));
        self::assertSame('php-tzdb', $original['timezoneSource']);
        self::assertSame($original, $changed);
        self::assertSame('2026-09-07T06:30:00Z', $original['occurrences'][0]['start']);
    }

    /** @param list<string> $actualStarts */
    #[DataProvider('gapRules')]
    public function testInitialGapOverrideDoesNotRestoreNominalTimeOfLaterOccurrences(string $rule, array $actualStarts, string $productNext): void
    {
        $override = "BEGIN:VEVENT\nUID:fixture@test\nDTSTAMP:20260901T000000Z\nRECURRENCE-ID;TZID=Europe/Paris:20260329T023000\nDTSTART:20260329T013000Z\nDTEND:20260329T014500Z\nEND:VEVENT\n";
        $ics = $this->fixture("DTSTART;TZID=Europe/Paris:20260329T023000\nDURATION:PT15M\nRRULE:".$rule, $override);
        $rows = (new IcalendarOracle())->expand($ics)['occurrences'];
        self::assertSame($actualStarts, array_column($rows, 'start'));
        // Product expectation remains 02:30 local, i.e. 00:30Z on March 30.
        self::assertNotSame($productNext, $rows[1]['start']);
    }

    /** @return iterable<string, array{string, list<string>, string}> */
    public static function gapRules(): iterable
    {
        yield 'daily' => ['FREQ=DAILY;COUNT=3', ['2026-03-29T01:30:00Z', '2026-03-30T01:30:00Z', '2026-03-31T01:30:00Z'], '2026-03-30T00:30:00Z'];
        yield 'weekly' => ['FREQ=WEEKLY;BYDAY=SU;COUNT=3', ['2026-03-29T01:30:00Z', '2026-04-05T01:30:00Z', '2026-04-12T01:30:00Z'], '2026-04-05T00:30:00Z'];
    }

    public function testAutumnDurationIsSeventyFiveMinutesInSabreButMustRemainFifteenInProduct(): void
    {
        $ics = $this->fixture("DTSTART;TZID=Europe/Paris:20261024T025500\nDURATION:PT15M\nRRULE:FREQ=DAILY;COUNT=3");
        $row = (new IcalendarOracle())->expand($ics)['occurrences'][1];
        self::assertSame('2026-10-25T00:55:00Z', $row['start']);
        self::assertSame('2026-10-25T02:10:00Z', $row['end']);
        self::assertSame(4500, $row['endTimestamp'] - $row['startTimestamp']);
        // The unchanged product requirement is 900 seconds, ending at 01:10Z.
        self::assertNotSame('2026-10-25T01:10:00Z', $row['end']);
    }

    public function testInitialAmbiguousLocalTimeIsResolvedByPhpBeforeOverride(): void
    {
        $ics = $this->fixture("DTSTART;TZID=Europe/Paris:20261025T023000\nDURATION:PT15M\nRRULE:FREQ=DAILY;COUNT=2");
        $rows = (new IcalendarOracle())->expand($ics)['occurrences'];
        self::assertSame('2026-10-25T01:30:00Z', $rows[0]['start']);
        // RFC's first reading of the bare DTSTART is 00:30Z, not PHP's choice.
        self::assertNotSame('2026-10-25T00:30:00Z', $rows[0]['start']);
    }

    public function testLaterGapIsNormalizedInsteadOfSkippedBySabre(): void
    {
        $ics = $this->fixture("DTSTART;TZID=Europe/Paris:20270327T023000\nDURATION:PT15M\nRRULE:FREQ=DAILY;COUNT=3");
        $rows = (new IcalendarOracle())->expand($ics)['occurrences'];
        self::assertSame(['2027-03-27T01:30:00Z', '2027-03-28T01:30:00Z', '2027-03-29T00:30:00Z'], array_column($rows, 'start'));
        // 03:30 local on March 28: this does NOT establish recurrence conformance.
        // RFC recurrence expansion skips nonexistent later local instances.
        self::assertSame('03:30', (new \DateTimeImmutable($rows[1]['start']))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('H:i'));
    }

    public function testAdapterPreservesDuplicateResults(): void
    {
        $ics = $this->fixture("DTSTART:20260907T063000Z\nDURATION:PT15M\nRDATE:20260907T063000Z");
        $rows = (new IcalendarOracle())->expand($ics)['occurrences'];
        self::assertCount(2, $rows);
        self::assertSame($rows[0], $rows[1]);
    }

    public function testUntilIsInclusiveWithoutAdapterFiltering(): void
    {
        $ics = $this->fixture("DTSTART:20260907T063000Z\nDURATION:PT15M\nRRULE:FREQ=DAILY;UNTIL=20260909T063000Z");
        self::assertSame(['2026-09-07T06:30:00Z', '2026-09-08T06:30:00Z', '2026-09-09T06:30:00Z'], array_column((new IcalendarOracle())->expand($ics)['occurrences'], 'start'));
    }

    public function testEarlierUntilIsSilentlyRaisedBySabre(): void
    {
        $ics = $this->fixture("DTSTART:20260907T063000Z\nDURATION:PT15M\nRRULE:FREQ=DAILY;UNTIL=20260901T063000Z");
        self::assertCount(1, (new IcalendarOracle())->expand($ics)['occurrences']);
    }

    public function testMaximumIsAnErrorAndGlobalSettingIsRestored(): void
    {
        $limit = Settings::$maxRecurrences;
        $ics = $this->fixture("DTSTART:20260907T063000Z\nDURATION:PT15M\nRRULE:FREQ=DAILY;COUNT=2001");
        try {
            (new IcalendarOracle())->expand($ics);
            self::fail('Le dépassement doit échouer.');
        } catch (MaxInstancesExceededException) {
            self::assertSame($limit, Settings::$maxRecurrences);
        }
    }

    public function testInvalidOutputIsNotRepaired(): void
    {
        $this->expectException(\Exception::class);
        (new IcalendarOracle())->expand("ceci n’est pas un calendrier\r\n");
    }

    public function testMultipleUidsRetainCoincidentOccurrencesAndOverrides(): void
    {
        $extra = "BEGIN:VEVENT\nUID:second@test\nDTSTAMP:20260901T000000Z\nDTSTART:20260907T063000Z\nDURATION:PT15M\nEND:VEVENT\n"
            ."BEGIN:VEVENT\nUID:fixture@test\nDTSTAMP:20260901T000000Z\nRECURRENCE-ID:20260908T063000Z\nDTSTART:20260908T073000Z\nDTEND:20260908T074500Z\nEND:VEVENT\n";
        $ics = $this->fixture("DTSTART:20260907T063000Z\nDURATION:PT15M\nRRULE:FREQ=DAILY;COUNT=2", $extra);
        $rows = (new IcalendarOracle())->expand($ics)['occurrences'];
        self::assertCount(3, $rows);
        self::assertSame(['fixture@test', 'second@test', 'fixture@test'], array_column($rows, 'uid'));
        self::assertSame(['2026-09-07T06:30:00Z', '2026-09-07T06:30:00Z', '2026-09-08T07:30:00Z'], array_column($rows, 'start'));
        self::assertSame('2026-09-08T07:45:00Z', $rows[2]['end']);
    }

    public static function invalidGroups(): iterable
    {
        yield 'empty calendar' => ["BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n"];
        foreach (['', "UID:first@test\nRECURRENCE-ID:20260907T063000Z", 'UID:first@test'] as $index => $properties) {
            $events = "BEGIN:VEVENT\n$properties\nDTSTART:20260907T063000Z\nDURATION:PT15M\nEND:VEVENT\n";
            if (2 === $index) {
                $events .= $events;
            }
            yield ['missing UID', 'orphan override', 'duplicate master'][$index] => [str_replace("\n", "\r\n", "BEGIN:VCALENDAR\nVERSION:2.0\n".$events."END:VCALENDAR\n")];
        }
    }

    #[DataProvider('invalidGroups')]
    public function testEveryUidRequiresExactlyOneMaster(string $ics): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new IcalendarOracle())->expand($ics);
    }

    public function testGlobalMaximumAcrossUidsIsAnErrorAndRestoresSettings(): void
    {
        $extra = "BEGIN:VEVENT\nUID:second@test\nDTSTAMP:20260901T000000Z\nDTSTART:20260907T063000Z\nDURATION:PT15M\nRRULE:FREQ=DAILY;COUNT=1001\nEND:VEVENT\n";
        $ics = $this->fixture("DTSTART:20260907T063000Z\nDURATION:PT15M\nRRULE:FREQ=DAILY;COUNT=1000", $extra);
        $limit = Settings::$maxRecurrences;
        try {
            (new IcalendarOracle())->expand($ics);
            self::fail('Le plafond doit porter sur la somme des groupes.');
        } catch (\RuntimeException $error) {
            self::assertSame('Plafond de développement dépassé.', $error->getMessage());
            self::assertSame($limit, Settings::$maxRecurrences);
        }
    }
}
