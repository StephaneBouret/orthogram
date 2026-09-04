<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Entity\LearningReminder;
use App\Entity\User;
use App\Enum\LearningReminderFrequency;
use App\Services\LearningReminderViewService;
use PHPUnit\Framework\TestCase;

final class LearningReminderViewServiceTest extends TestCase
{
    private LearningReminderViewService $service;

    protected function setUp(): void
    {
        $this->service = new LearningReminderViewService();
    }

    public function testDailySummary(): void
    {
        $result = $this->service->present($this->createReminder(
            LearningReminderFrequency::DAILY,
            new \DateTimeImmutable('08:00 UTC'),
            [],
            null,
        ));

        self::assertSame('Tous les jours à 8 h', $result['summary']);
    }

    public function testWeeklySummary(): void
    {
        $result = $this->service->present($this->createReminder(
            LearningReminderFrequency::WEEKLY,
            new \DateTimeImmutable('18:30 UTC'),
            [1, 3, 5],
            null,
        ));

        self::assertSame(
            'Tous les lundis, mercredis et vendredis à 18 h 30',
            $result['summary'],
        );
    }

    public function testOnceSummaryUsesLongFrenchDate(): void
    {
        $result = $this->service->present($this->createReminder(
            LearningReminderFrequency::ONCE,
            new \DateTimeImmutable('11:35 UTC'),
            [],
            new \DateTimeImmutable('2026-09-03 UTC'),
        ));

        self::assertSame(
            'Le 3 septembre 2026 à 11 h 35',
            $result['summary'],
        );
    }

    /**
     * @param list<int> $weekdays
     */
    private function createReminder(
        LearningReminderFrequency $frequency,
        \DateTimeImmutable $time,
        array $weekdays,
        ?\DateTimeImmutable $scheduledDate,
    ): LearningReminder {
        $createdAt = new \DateTimeImmutable('2026-09-01 10:00:00 UTC');

        return LearningReminder::create(
            new User(),
            $frequency,
            $time,
            $weekdays,
            $scheduledDate,
            'Europe/Paris',
            new \DateTimeImmutable('2026-09-10 10:00:00 UTC'),
            $createdAt,
        );
    }
}
