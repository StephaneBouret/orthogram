<?php

declare(strict_types=1);

namespace App\Services;

use App\Enum\LearningReminderFrequency;
use Psr\Clock\ClockInterface;

final class LearningReminderNextRunCalculator
{
    private const TRANSITION_LOOKAROUND_SECONDS = 172800;

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param list<int> $weekdays
     */
    public function calculate(
        LearningReminderFrequency $frequency,
        \DateTimeImmutable $reminderTime,
        array $weekdays,
        ?\DateTimeImmutable $scheduledDate,
        string $timezone,
    ): \DateTimeImmutable {
        $timeZone = $this->createTimeZone($timezone);
        $normalizedWeekdays = $this->normalizeWeekdays($frequency, $weekdays);
        $this->assertScheduledDateMatchesFrequency($frequency, $scheduledDate);

        $now = $this->clock
            ->now()
            ->setTimezone(new \DateTimeZone('UTC'));

        return match ($frequency) {
            LearningReminderFrequency::DAILY => $this->calculateDaily($now, $reminderTime, $timeZone),
            LearningReminderFrequency::WEEKLY => $this->calculateWeekly(
                $now,
                $reminderTime,
                $normalizedWeekdays,
                $timeZone,
            ),
            LearningReminderFrequency::ONCE => $this->calculateOnce(
                $now,
                $reminderTime,
                $scheduledDate,
                $timeZone,
            ),
        };
    }

    private function calculateDaily(
        \DateTimeImmutable $now,
        \DateTimeImmutable $reminderTime,
        \DateTimeZone $timeZone,
    ): \DateTimeImmutable {
        $localDate = $now
            ->setTimezone($timeZone)
            ->setTime(0, 0, 0);

        for ($dayOffset = 0; $dayOffset <= 1; ++$dayOffset) {
            $date = 0 === $dayOffset ? $localDate : $localDate->modify('+1 day');
            $candidate = $this->resolveLocalDateTime($date, $reminderTime, $timeZone);

            if ($candidate > $now) {
                return $candidate;
            }
        }

        throw new \LogicException('Impossible de calculer la prochaine occurrence quotidienne.');
    }

    /**
     * @param non-empty-list<int> $weekdays
     */
    private function calculateWeekly(
        \DateTimeImmutable $now,
        \DateTimeImmutable $reminderTime,
        array $weekdays,
        \DateTimeZone $timeZone,
    ): \DateTimeImmutable {
        $localDate = $now
            ->setTimezone($timeZone)
            ->setTime(0, 0, 0);

        for ($dayOffset = 0; $dayOffset <= 7; ++$dayOffset) {
            $date = 0 === $dayOffset
                ? $localDate
                : $localDate->modify(sprintf('+%d days', $dayOffset));

            if (!\in_array((int) $date->format('N'), $weekdays, true)) {
                continue;
            }

            $candidate = $this->resolveLocalDateTime($date, $reminderTime, $timeZone);

            if ($candidate > $now) {
                return $candidate;
            }
        }

        throw new \LogicException('Impossible de calculer la prochaine occurrence hebdomadaire.');
    }

    private function calculateOnce(
        \DateTimeImmutable $now,
        \DateTimeImmutable $reminderTime,
        ?\DateTimeImmutable $scheduledDate,
        \DateTimeZone $timeZone,
    ): \DateTimeImmutable {
        if (null === $scheduledDate) {
            throw new \LogicException('La date d’un rappel unique a déjà été validée.');
        }

        $candidate = $this->resolveLocalDateTime($scheduledDate, $reminderTime, $timeZone);

        if ($candidate <= $now) {
            throw new \InvalidArgumentException('La date et l’heure du rappel unique doivent être strictement futures.');
        }

        return $candidate;
    }

    private function resolveLocalDateTime(
        \DateTimeImmutable $date,
        \DateTimeImmutable $time,
        \DateTimeZone $timeZone,
    ): \DateTimeImmutable {
        $wallValue = sprintf('%s %s', $date->format('Y-m-d'), $time->format('H:i:s'));
        $wallAsUtc = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $wallValue,
            new \DateTimeZone('UTC'),
        );

        if (false === $wallAsUtc) {
            throw new \LogicException('Impossible de construire la date civile.');
        }

        $wallTimestamp = $wallAsUtc->getTimestamp();
        $transitions = $timeZone->getTransitions(
            $wallTimestamp - self::TRANSITION_LOOKAROUND_SECONDS,
            $wallTimestamp + self::TRANSITION_LOOKAROUND_SECONDS,
        );

        if ([] === $transitions) {
            throw new \LogicException('Impossible de lire les transitions du fuseau horaire.');
        }

        $offsets = [];

        foreach ($transitions as $transition) {
            $offsets[$transition['offset']] = true;
        }

        $matchingTimestamps = [];

        foreach (array_keys($offsets) as $offset) {
            $candidateTimestamp = $wallTimestamp - $offset;
            $candidate = $this->utcFromTimestamp($candidateTimestamp);

            if ($candidate->setTimezone($timeZone)->format('Y-m-d H:i:s') === $wallValue) {
                $matchingTimestamps[] = $candidateTimestamp;
            }
        }

        if ([] !== $matchingTimestamps) {
            return $this->utcFromTimestamp(max($matchingTimestamps));
        }

        $previousOffset = $transitions[0]['offset'];

        foreach (array_slice($transitions, 1) as $transition) {
            $newOffset = $transition['offset'];

            if ($newOffset > $previousOffset) {
                $gapStart = $transition['ts'] + $previousOffset;
                $gapEnd = $transition['ts'] + $newOffset;

                if ($wallTimestamp >= $gapStart && $wallTimestamp < $gapEnd) {
                    return $this->utcFromTimestamp($wallTimestamp - $previousOffset);
                }
            }

            $previousOffset = $newOffset;
        }

        throw new \LogicException(sprintf('La date civile "%s" ne peut pas être résolue dans le fuseau "%s".', $wallValue, $timeZone->getName()));
    }

    private function createTimeZone(string $timezone): \DateTimeZone
    {
        /** @var array<string, true>|null $identifiers */
        static $identifiers = null;

        $identifiers ??= array_fill_keys(
            \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),
            true,
        );

        if (!isset($identifiers[$timezone])) {
            throw new \InvalidArgumentException(sprintf('Le fuseau horaire "%s" n’est pas un identifiant IANA valide.', $timezone));
        }

        return new \DateTimeZone($timezone);
    }

    /**
     * @param list<mixed> $weekdays
     *
     * @return list<int>
     */
    private function normalizeWeekdays(LearningReminderFrequency $frequency, array $weekdays): array
    {
        if (!$frequency->requiresWeekdays()) {
            if ([] !== $weekdays) {
                throw new \InvalidArgumentException('Seule une fréquence hebdomadaire peut définir des jours.');
            }

            return [];
        }

        if ([] === $weekdays) {
            throw new \InvalidArgumentException('Une fréquence hebdomadaire doit définir au moins un jour.');
        }

        $normalized = [];

        foreach ($weekdays as $weekday) {
            if (!\is_int($weekday) || $weekday < 1 || $weekday > 7) {
                throw new \InvalidArgumentException('Les jours doivent être des entiers ISO compris entre 1 et 7.');
            }

            $normalized[$weekday] = true;
        }

        $result = array_keys($normalized);
        sort($result, \SORT_NUMERIC);

        return $result;
    }

    private function assertScheduledDateMatchesFrequency(
        LearningReminderFrequency $frequency,
        ?\DateTimeImmutable $scheduledDate,
    ): void {
        if ($frequency->requiresScheduledDate() && null === $scheduledDate) {
            throw new \InvalidArgumentException('Une fréquence unique doit définir une date.');
        }

        if (!$frequency->requiresScheduledDate() && null !== $scheduledDate) {
            throw new \InvalidArgumentException('Seule une fréquence unique peut définir une date.');
        }
    }

    private function utcFromTimestamp(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@'.$timestamp))
            ->setTimezone(new \DateTimeZone('UTC'));
    }
}
