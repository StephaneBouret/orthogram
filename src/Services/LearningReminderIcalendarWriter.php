<?php

declare(strict_types=1);

namespace App\Services;

use App\Dto\LearningReminderPayload;

final class LearningReminderIcalendarWriter
{
    public function write(
        LearningReminderPayload $payload,
        string $nominal,
        \DateTimeImmutable $first,
        ?\DateTimeImmutable $until,
        \DateTimeImmutable $stamp,
        string $url,
        string $description,
        ?string $seriesNominal = null,
    ): string {
        $utc = new \DateTimeZone('UTC');
        $first = $first->setTimezone($utc);
        $end = $first->modify('+'.LearningReminderCalendarService::DURATION_SECONDS.' seconds');
        if (null === $until && null !== $seriesNominal) {
            throw new \InvalidArgumentException('Un événement unique ne peut pas définir une série séparée.');
        }
        if (null !== $until) {
            $separate = $this->requiresSeparateFirst($nominal, $payload->timezone, $first);
            if ($separate !== (null !== $seriesNominal)
                || ($separate && $seriesNominal !== $this->nextSeriesNominal($payload, $nominal, $first, $until))) {
                throw new \InvalidArgumentException('La séparation et le prochain départ nominal doivent correspondre aux instants exportés.');
            }
        }
        // Each independent object receives its own opaque identity.
        $uid = bin2hex(random_bytes(16)).'@orthogram';
        $common = [
            'UID:'.$uid,
            'DTSTAMP:'.$stamp->setTimezone($utc)->format('Ymd\THis\Z'),
            'SUMMARY:'.$this->escape(LearningReminderCalendarService::TITLE),
            'DESCRIPTION:'.$this->escape($description),
            // URL is a URI value, not an iCalendar TEXT property.
            'URL:'.str_replace(["\r", "\n"], ['%0D', '%0A'], $url),
        ];
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Orthogram//Learning calendar//FR', 'CALSCALE:GREGORIAN'];
        if (null !== $until) {
            $until = $until->setTimezone($utc);
            $lines[] = 'X-ORTHOGRAM-UNTIL:'.$until->format('Ymd\THis\Z');
            $coverageEnd = $until->modify('+'.LearningReminderCalendarService::DURATION_SECONDS.' seconds');
            array_push($lines, ...$this->timezone($payload->timezone, $first, $coverageEnd));
        }
        if (null !== $seriesNominal) {
            $standalone = $common;
            $standalone[0] = 'UID:'.bin2hex(random_bytes(16)).'@orthogram';
            array_push($lines, 'BEGIN:VEVENT', ...$standalone);
            array_push($lines, 'DTSTART:'.$first->format('Ymd\THis\Z'), 'DTEND:'.$end->format('Ymd\THis\Z'), 'END:VEVENT');
        }
        array_push($lines, 'BEGIN:VEVENT', ...$common);
        if (null === $until) {
            $lines[] = 'DTSTART:'.$first->format('Ymd\THis\Z');
            $lines[] = 'DTEND:'.$end->format('Ymd\THis\Z');
        } else {
            $lines[] = 'DTSTART;TZID='.$payload->timezone.':'.($seriesNominal ?? $nominal);
            $lines[] = 'DURATION:PT'.intdiv(LearningReminderCalendarService::DURATION_SECONDS, 60).'M';
            $rule = 'RRULE:FREQ='.strtoupper($payload->frequency);
            if ('weekly' === $payload->frequency) {
                $names = [1 => 'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
                $days = $payload->weekdays;
                sort($days);
                $rule .= ';BYDAY='.implode(',', array_map(static fn (int $day): string => $names[$day], $days)).';WKST=MO';
            }
            $lines[] = $rule.';UNTIL='.$until->format('Ymd\THis\Z');
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    public function requiresSeparateFirst(string $nominal, string $tzid, \DateTimeImmutable $first): bool
    {
        $instants = $this->nominalInstants($nominal, $tzid);
        $timestamp = $first->getTimestamp();
        if ([] === $instants['candidates']) {
            // Preserve A: resolve the nonexistent start with the pre-gap offset.
            if ($timestamp !== $instants['gapInstant']) {
                throw new \InvalidArgumentException('L’instant initial ne correspond pas à l’heure inexistante demandée.');
            }

            return true;
        }
        if (!\in_array($timestamp, $instants['candidates'], true)) {
            throw new \InvalidArgumentException('L’instant initial ne correspond à aucun candidat de l’heure civile demandée.');
        }
        // RFC 5545: an ambiguous nominal DTSTART denotes its earliest passage.
        return $timestamp !== $instants['candidates'][0];
    }

    public function nextSeriesNominal(LearningReminderPayload $payload, string $nominal, \DateTimeImmutable $first, \DateTimeImmutable $until): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Ymd\THis', $nominal, new \DateTimeZone('UTC'));
        if (false === $date || $date->format('Ymd\THis') !== $nominal) {
            throw new \InvalidArgumentException('Date nominale invalide.');
        }
        // UTC here is only a civil-date carrier, never a 24-hour shift of $first.
        for ($day = 1; $day <= 7; ++$day) {
            $date = $date->modify('+1 day');
            if ('weekly' === $payload->frequency && !\in_array((int) $date->format('N'), $payload->weekdays, true)) {
                continue;
            }
            $civil = $date->format('Ymd\THis');
            $candidates = $this->nominalInstants($civil, $payload->timezone)['candidates'];
            // Never skip an eligible date to hide an unrepresentable occurrence.
            if (1 !== \count($candidates) || $candidates[0] <= $first->getTimestamp() || $candidates[0] > $until->getTimestamp()) {
                throw new \InvalidArgumentException('La prochaine date nominale doit être non ambiguë, postérieure au premier rendez-vous et comprise dans la période exportée.');
            }

            return $civil;
        }
        throw new \InvalidArgumentException('Prochain jour de récurrence introuvable.');
    }

    /** @return array{candidates: list<int>, gapInstant: ?int} */
    private function nominalInstants(string $nominal, string $tzid): array
    {
        $wall = \DateTimeImmutable::createFromFormat('!Ymd\THis', $nominal, new \DateTimeZone('UTC'));
        if (false === $wall || $wall->format('Ymd\THis') !== $nominal) {
            throw new \InvalidArgumentException('Date civile invalide.');
        }
        $zone = new \DateTimeZone($tzid);
        $transitions = $zone->getTransitions($wall->getTimestamp() - 172800, $wall->getTimestamp() + 172800);
        if (!$transitions) {
            throw new \LogicException('Transitions IANA indisponibles.');
        }
        $offsets = array_unique(array_column($transitions, 'offset'));
        $candidates = [];
        foreach ($offsets as $offset) {
            $timestamp = $wall->getTimestamp() - $offset;
            if ((new \DateTimeImmutable('@'.$timestamp))->setTimezone($zone)->format('Ymd\THis') === $nominal) {
                $candidates[] = $timestamp;
            }
        }
        sort($candidates, \SORT_NUMERIC);
        $gapInstant = null;
        $previous = $transitions[0]['offset'];
        foreach (array_slice($transitions, 1) as $transition) {
            if ($transition['offset'] > $previous
                && $wall->getTimestamp() >= $transition['ts'] + $previous
                && $wall->getTimestamp() < $transition['ts'] + $transition['offset']) {
                $gapInstant = $wall->getTimestamp() - $previous;
            }
            $previous = $transition['offset'];
        }

        return ['candidates' => $candidates, 'gapInstant' => $gapInstant];
    }

    /** @return list<string> */
    private function timezone(string $tzid, \DateTimeImmutable $first, \DateTimeImmutable $end): array
    {
        $begin = $first->modify('-370 days')->getTimestamp();
        $transitions = (new \DateTimeZone($tzid))->getTransitions($begin, $end->getTimestamp() + 1);
        if (!$transitions) {
            throw new \LogicException('Transitions IANA indisponibles.');
        }
        $lines = ['BEGIN:VTIMEZONE', 'TZID:'.$tzid, 'X-ORTHOGRAM-COVERAGE-END:'.$end->format('Ymd\THis\Z')];
        $previous = $transitions[0]['offset'];
        foreach ($transitions as $transition) {
            // The first synthetic observance establishes the offset before DTSTART.
            // Each subsequent DTSTART is the onset expressed with TZOFFSETFROM.
            $type = $transition['isdst'] ? 'DAYLIGHT' : 'STANDARD';
            array_push(
                $lines,
                'BEGIN:'.$type,
                'DTSTART:'.gmdate('Ymd\THis', $transition['ts'] + $previous),
                'TZOFFSETFROM:'.$this->offset($previous),
                'TZOFFSETTO:'.$this->offset($transition['offset']),
                'TZNAME:'.$this->escape($transition['abbr']),
                'END:'.$type,
            );
            $previous = $transition['offset'];
        }
        $lines[] = 'END:VTIMEZONE';

        return $lines;
    }

    private function offset(int $seconds): string
    {
        $absolute = abs($seconds);

        return ($seconds < 0 ? '-' : '+').sprintf('%02d%02d', intdiv($absolute, 3600), intdiv($absolute % 3600, 60)).(0 === $absolute % 60 ? '' : sprintf('%02d', $absolute % 60));
    }

    private function escape(string $text): string
    {
        return str_replace(["\r\n", "\r", "\n", ';', ','], ['\\n', '\\n', '\\n', '\\;', '\\,'], str_replace('\\', '\\\\', $text));
    }

    private function fold(string $line): string
    {
        $parts = [];
        while (\strlen($line) > 75) {
            $part = mb_strcut($line, 0, 75, 'UTF-8');
            $parts[] = $part;
            $line = ' '.substr($line, \strlen($part));
        }
        $parts[] = $line;

        return implode("\r\n", $parts);
    }
}
