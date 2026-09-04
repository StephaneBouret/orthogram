<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\LearningReminder;
use App\Enum\LearningReminderFrequency;

final class LearningReminderViewService
{
    private const MONTH_LABELS = [
        1 => 'janvier',
        2 => 'février',
        3 => 'mars',
        4 => 'avril',
        5 => 'mai',
        6 => 'juin',
        7 => 'juillet',
        8 => 'août',
        9 => 'septembre',
        10 => 'octobre',
        11 => 'novembre',
        12 => 'décembre',
    ];

    private const WEEKDAY_LABELS = [
        1 => 'lundis',
        2 => 'mardis',
        3 => 'mercredis',
        4 => 'jeudis',
        5 => 'vendredis',
        6 => 'samedis',
        7 => 'dimanches',
    ];

    /**
     * @return array{
     *     id: int|null,
     *     enabled: bool,
     *     frequency: string,
     *     reminderTime: string,
     *     weekdays: list<int>,
     *     scheduledDate: string|null,
     *     timezone: string,
     *     nextRunAt: string|null,
     *     lastSentAt: string|null,
     *     summary: string
     * }
     */
    public function present(LearningReminder $reminder): array
    {
        return [
            'id' => $reminder->getId(),
            'enabled' => $reminder->isEnabled(),
            'frequency' => $reminder->getFrequency()->value,
            'reminderTime' => $reminder->getReminderTime()->format('H:i'),
            'weekdays' => $reminder->getWeekdays(),
            'scheduledDate' => $reminder->getScheduledDate()?->format('Y-m-d'),
            'timezone' => $reminder->getTimezone(),
            'nextRunAt' => $reminder->getNextRunAt()?->format(\DateTimeInterface::ATOM),
            'lastSentAt' => $reminder->getLastSentAt()?->format(\DateTimeInterface::ATOM),
            'summary' => $this->summary($reminder),
        ];
    }

    private function summary(LearningReminder $reminder): string
    {
        $time = $this->formatTime($reminder->getReminderTime());

        return match ($reminder->getFrequency()) {
            LearningReminderFrequency::DAILY => sprintf('Tous les jours à %s', $time),
            LearningReminderFrequency::WEEKLY => sprintf(
                'Tous les %s à %s',
                $this->joinFrench($this->weekdayLabels($reminder->getWeekdays())),
                $time,
            ),
            LearningReminderFrequency::ONCE => sprintf(
                'Le %s à %s',
                $this->formatScheduledDate($reminder),
                $time,
            ),
        };
    }

    /**
     * @param list<int> $weekdays
     *
     * @return non-empty-list<string>
     */
    private function weekdayLabels(array $weekdays): array
    {
        $labels = [];

        foreach ($weekdays as $weekday) {
            if (!isset(self::WEEKDAY_LABELS[$weekday])) {
                throw new \UnexpectedValueException(sprintf('Le jour ISO "%d" ne peut pas être présenté.', $weekday));
            }

            $labels[] = self::WEEKDAY_LABELS[$weekday];
        }

        if ([] === $labels) {
            throw new \UnexpectedValueException('Un rappel hebdomadaire doit contenir au moins un jour.');
        }

        return $labels;
    }

    private function formatScheduledDate(LearningReminder $reminder): string
    {
        $date = $reminder->getScheduledDate();

        if (!$date instanceof \DateTimeImmutable) {
            throw new \UnexpectedValueException('Un rappel unique doit contenir une date planifiée.');
        }

        $month = (int) $date->format('n');

        return sprintf(
            '%d %s %s',
            (int) $date->format('j'),
            self::MONTH_LABELS[$month],
            $date->format('Y'),
        );
    }

    private function formatTime(\DateTimeImmutable $time): string
    {
        $hours = (int) $time->format('H');
        $minutes = $time->format('i');

        return '00' === $minutes
            ? sprintf('%d h', $hours)
            : sprintf('%d h %s', $hours, $minutes);
    }

    /**
     * @param non-empty-list<string> $items
     */
    private function joinFrench(array $items): string
    {
        $count = \count($items);

        if (1 === $count) {
            return $items[0];
        }

        if (2 === $count) {
            return implode(' et ', $items);
        }

        return sprintf(
            '%s et %s',
            implode(', ', array_slice($items, 0, -1)),
            $items[$count - 1],
        );
    }
}
