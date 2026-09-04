<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Enum\LearningReminderFrequency;
use App\Services\LearningReminderNextRunCalculator;
use PHPUnit\Framework\TestCase;

final class LearningReminderNextRunCalculatorTest extends TestCase
{
    public function testDailyOccurrenceBeforeConfiguredTimeUsesToday(): void
    {
        $calculator = $this->calculator();

        $result = $calculator->calculate(
            LearningReminderFrequency::DAILY,
            $this->time('08:00:00'),
            [],
            null,
            'Europe/Paris',
            $this->instant('2026-09-03 05:00:00 UTC'),
        );

        $this->assertUtcInstant('2026-09-03 06:00:00', $result);
    }

    public function testDailyOccurrenceAfterConfiguredTimeUsesTomorrow(): void
    {
        $calculator = $this->calculator();

        $result = $calculator->calculate(
            LearningReminderFrequency::DAILY,
            $this->time('08:00:00'),
            [],
            null,
            'Europe/Paris',
            $this->instant('2026-09-03 07:00:00 UTC'),
        );

        $this->assertUtcInstant('2026-09-04 06:00:00', $result);
    }

    public function testDailyOccurrenceAtCurrentInstantUsesTomorrow(): void
    {
        $calculator = $this->calculator();

        $result = $calculator->calculate(
            LearningReminderFrequency::DAILY,
            $this->time('08:00:00'),
            [],
            null,
            'Europe/Paris',
            $this->instant('2026-09-03 06:00:00 UTC'),
        );

        $this->assertUtcInstant('2026-09-04 06:00:00', $result);
    }

    public function testWeeklyOccurrenceSupportsMultipleDays(): void
    {
        $calculator = $this->calculator();

        $result = $calculator->calculate(
            LearningReminderFrequency::WEEKLY,
            $this->time('08:00:00'),
            [5, 1],
            null,
            'Europe/Paris',
            $this->instant('2026-09-02 12:00:00 UTC'),
        );

        $this->assertUtcInstant('2026-09-04 06:00:00', $result);
    }

    public function testWeeklyOccurrenceForCurrentDayAlreadyPassedUsesNextWeek(): void
    {
        $calculator = $this->calculator();

        $result = $calculator->calculate(
            LearningReminderFrequency::WEEKLY,
            $this->time('09:00:00'),
            [1],
            null,
            'Europe/Paris',
            $this->instant('2026-09-07 10:00:00 UTC'),
        );

        $this->assertUtcInstant('2026-09-14 07:00:00', $result);
    }

    public function testOnceOccurrenceMustBeFutureAndIgnoresCivilValueTimezones(): void
    {
        $calculator = $this->calculator();

        $result = $calculator->calculate(
            LearningReminderFrequency::ONCE,
            $this->time('10:15:00', 'America/Los_Angeles'),
            [],
            $this->date('2026-09-04', 'Asia/Tokyo'),
            'Europe/Paris',
            $this->instant('2026-09-03 12:00:00 UTC'),
        );

        $this->assertUtcInstant('2026-09-04 08:15:00', $result);
    }

    public function testOnceOccurrenceInPastIsRejected(): void
    {
        $calculator = $this->calculator();

        $this->expectException(\InvalidArgumentException::class);

        $calculator->calculate(
            LearningReminderFrequency::ONCE,
            $this->time('10:00:00'),
            [],
            $this->date('2026-09-03'),
            'Europe/Paris',
            $this->instant('2026-09-03 12:00:00 UTC'),
        );
    }

    public function testInvalidTimezoneIsRejected(): void
    {
        $calculator = $this->calculator();

        $this->expectException(\InvalidArgumentException::class);

        $calculator->calculate(
            LearningReminderFrequency::DAILY,
            $this->time('10:00:00'),
            [],
            null,
            'Invalid/Timezone',
            $this->instant('2026-09-03 12:00:00 UTC'),
        );
    }

    public function testNonexistentSpringTimeIsNormalizedForward(): void
    {
        $calculator = $this->calculator();

        $result = $calculator->calculate(
            LearningReminderFrequency::DAILY,
            $this->time('02:30:00'),
            [],
            null,
            'Europe/Paris',
            $this->instant('2026-03-28 12:00:00 UTC'),
        );

        $this->assertUtcInstant('2026-03-29 01:30:00', $result);
        self::assertSame(
            '2026-03-29 03:30:00 CEST',
            $result
                ->setTimezone(new \DateTimeZone('Europe/Paris'))
                ->format('Y-m-d H:i:s T'),
        );
    }

    public function testRepeatedAutumnTimeUsesSecondStandardOccurrence(): void
    {
        $calculator = $this->calculator();

        $result = $calculator->calculate(
            LearningReminderFrequency::DAILY,
            $this->time('02:30:00'),
            [],
            null,
            'Europe/Paris',
            $this->instant('2026-10-24 12:00:00 UTC'),
        );

        $this->assertUtcInstant('2026-10-25 01:30:00', $result);
        self::assertSame(
            '2026-10-25 02:30:00 CET',
            $result
                ->setTimezone(new \DateTimeZone('Europe/Paris'))
                ->format('Y-m-d H:i:s T'),
        );
    }

    public function testWeeklyFrequencyRejectsEmptyWeekdays(): void
    {
        $calculator = $this->calculator();

        $this->expectException(\InvalidArgumentException::class);

        $calculator->calculate(
            LearningReminderFrequency::WEEKLY,
            $this->time('10:00:00'),
            [],
            null,
            'Europe/Paris',
            $this->instant('2026-09-03 12:00:00 UTC'),
        );
    }

    public function testWeekdaysOutsideIsoRangeAreRejected(): void
    {
        $calculator = $this->calculator();

        $this->expectException(\InvalidArgumentException::class);

        $calculator->calculate(
            LearningReminderFrequency::WEEKLY,
            $this->time('10:00:00'),
            [0, 8],
            null,
            'Europe/Paris',
            $this->instant('2026-09-03 12:00:00 UTC'),
        );
    }

    public function testVeryLateRecurringReminderProducesOnlyOneFutureOccurrence(): void
    {
        $result = $this->calculator()->calculate(
            LearningReminderFrequency::WEEKLY,
            $this->time('09:00:00'),
            [1, 3, 5],
            null,
            'Europe/Paris',
            $this->instant('2027-04-15 12:00:00 UTC'),
        );

        $this->assertUtcInstant('2027-04-16 07:00:00', $result);
        self::assertGreaterThan(
            $this->instant('2027-04-15 12:00:00 UTC'),
            $result,
        );
    }

    private function calculator(): LearningReminderNextRunCalculator
    {
        return new LearningReminderNextRunCalculator();
    }

    private function instant(string $instant): \DateTimeImmutable
    {
        return new \DateTimeImmutable($instant);
    }

    private function time(string $time, string $timezone = 'UTC'): \DateTimeImmutable
    {
        $value = \DateTimeImmutable::createFromFormat(
            '!H:i:s',
            $time,
            new \DateTimeZone($timezone),
        );

        self::assertInstanceOf(\DateTimeImmutable::class, $value);

        return $value;
    }

    private function date(string $date, string $timezone = 'UTC'): \DateTimeImmutable
    {
        $value = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date,
            new \DateTimeZone($timezone),
        );

        self::assertInstanceOf(\DateTimeImmutable::class, $value);

        return $value;
    }

    private function assertUtcInstant(string $expected, \DateTimeImmutable $actual): void
    {
        self::assertSame($expected, $actual->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $actual->getTimezone()->getName());
    }
}
