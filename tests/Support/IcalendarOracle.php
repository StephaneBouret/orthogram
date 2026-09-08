<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\NoInstancesException;
use Sabre\VObject\Settings;

/** Partial oracle: the native PHP timezone database, NOT embedded VTIMEZONE rules. */
final class IcalendarOracle
{
    private const MAX_OCCURRENCES = 2000;

    /** @return array{status: string, timezoneSource: string, occurrences: list<array{uid: string, start: string, end: string, startTimestamp: int, endTimestamp: int}>} */
    public function expand(string $ics): array
    {
        if (!class_exists(Reader::class)) {
            throw new \RuntimeException('sabre/vobject est requis pour les tests calendrier.');
        }
        $calendar = Reader::read($ics);
        if (!$calendar instanceof VCalendar) {
            throw new \UnexpectedValueException('VCALENDAR attendu.');
        }
        $events = array_values($calendar->select('VEVENT'));
        $groups = [];
        foreach ($events as $event) {
            $eventUid = (string) $event->UID;
            if ('' === trim($eventUid)) {
                throw new \UnexpectedValueException('Un UID est requis pour chaque événement.');
            }
            $groups[$eventUid][] = $event;
        }
        if ([] === $groups) {
            throw new \UnexpectedValueException('Au moins un événement maître est attendu.');
        }
        foreach ($groups as $group) {
            $masterCount = 0;
            foreach ($group as $event) {
                if (!isset($event->{'RECURRENCE-ID'})) {
                    ++$masterCount;
                }
            }
            if (1 !== $masterCount) {
                throw new \UnexpectedValueException('Un unique événement maître par UID est attendu.');
            }
        }
        $previousLimit = Settings::$maxRecurrences;
        Settings::$maxRecurrences = self::MAX_OCCURRENCES;
        try {
            $utc = new \DateTimeZone('UTC');
            $rows = [];
            foreach ($groups as $uid => $group) {
                try {
                    $iterator = new EventIterator($group);
                } catch (NoInstancesException) {
                    // The engine returned no instances for this UID; other groups remain.
                    continue;
                }
                while ($iterator->valid()) {
                    if (\count($rows) >= self::MAX_OCCURRENCES) {
                        throw new \RuntimeException('Plafond de développement dépassé.');
                    }
                    $start = $iterator->getDtStart();
                    $end = $iterator->getDtEnd();
                    if (null === $start || null === $end) {
                        throw new \UnexpectedValueException('Occurrence sans début ou fin exploitable.');
                    }
                    $rows[] = [
                        'uid' => (string) $uid,
                        'start' => $start->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
                        'end' => $end->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
                        'startTimestamp' => $start->getTimestamp(),
                        'endTimestamp' => $end->getTimestamp(),
                    ];
                    $iterator->next();
                }
            }
            // Sort for comparison only: retain all engine results, including duplicates.
            usort($rows, static fn (array $a, array $b): int => $a['startTimestamp'] <=> $b['startTimestamp']);

            return ['status' => [] === $rows ? 'no_instances' : 'ok', 'timezoneSource' => 'php-tzdb', 'occurrences' => $rows];
        } finally {
            Settings::$maxRecurrences = $previousLimit;
        }
    }
}
