<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\LearningReminder;
use App\Entity\User;
use App\Enum\LearningReminderFrequency;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LearningReminderTest extends KernelTestCase
{
    public function testDoctrineMetadataRequiresUserAndDeclaresUniqueConstraint(): void
    {
        self::bootKernel();

        $registry = self::getContainer()->get(ManagerRegistry::class);
        $manager = $registry->getManagerForClass(LearningReminder::class);

        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        $metadata = $manager->getClassMetadata(LearningReminder::class);
        $association = $metadata->getAssociationMapping('user');

        self::assertInstanceOf(ToOneOwningSideMapping::class, $association);
        self::assertTrue($association->isOneToOne());
        self::assertCount(1, $association->joinColumns);
        self::assertSame('user_id', $association->joinColumns[0]->name);
        self::assertFalse($association->joinColumns[0]->nullable);
        self::assertSame('CASCADE', $association->joinColumns[0]->onDelete);
        self::assertTrue($association->joinColumns[0]->unique);

        $constraints = $metadata->table['uniqueConstraints'] ?? [];

        self::assertArrayHasKey('UNIQ_LEARNING_REMINDER_USER', $constraints);
        self::assertSame(
            ['user_id'],
            $constraints['UNIQ_LEARNING_REMINDER_USER']['columns'],
        );

        $indexes = $metadata->table['indexes'] ?? [];

        self::assertArrayHasKey('IDX_LEARNING_REMINDER_DUE', $indexes);
        self::assertSame(
            ['enabled', 'next_run_at'],
            $indexes['IDX_LEARNING_REMINDER_DUE']['columns'],
        );

        $reflection = new \ReflectionProperty(LearningReminder::class, 'user');
        $attributes = $reflection->getAttributes(ORM\JoinColumn::class);

        self::assertCount(1, $attributes);
        self::assertFalse($attributes[0]->newInstance()->unique);

        $schema = (new SchemaTool($manager))->getSchemaFromMetadata([$metadata]);
        $table = $schema->getTable('learning_reminder');
        $uniqueAssetsCoveringUser = [];

        foreach ($table->getIndexes() as $index) {
            if (!$index->isUnique() || $index->isPrimary()) {
                continue;
            }

            $columns = array_map(
                static fn ($column): string => $column->getColumnName()->toString(),
                $index->getIndexedColumns(),
            );

            if (['user_id'] === $columns) {
                $uniqueAssetsCoveringUser[] = $index->getName();
            }
        }

        foreach ($table->getUniqueConstraints() as $constraint) {
            $columns = array_map(
                static fn ($column): string => $column->toString(),
                $constraint->getColumnNames(),
            );

            if (['user_id'] === $columns) {
                $uniqueAssetsCoveringUser[] = $constraint->getName();
            }
        }

        self::assertSame(
            ['UNIQ_LEARNING_REMINDER_USER'],
            $uniqueAssetsCoveringUser,
        );
    }

    public function testCreationRequiresUserAndCreatesEnabledReminder(): void
    {
        $user = new User();
        $createdAt = new \DateTimeImmutable(
            '2026-09-03 08:00:00',
            new \DateTimeZone('Europe/Paris'),
        );
        $nextRunAt = new \DateTimeImmutable(
            '2026-09-04 08:00:00',
            new \DateTimeZone('Europe/Paris'),
        );

        $reminder = LearningReminder::create(
            $user,
            LearningReminderFrequency::DAILY,
            $this->time('09:30:00', 'Asia/Tokyo'),
            [],
            null,
            'Europe/Paris',
            $nextRunAt,
            $createdAt,
        );

        self::assertSame($user, $reminder->getUser());
        self::assertTrue($reminder->isEnabled());
        self::assertSame(LearningReminderFrequency::DAILY, $reminder->getFrequency());
        self::assertSame('09:30:00', $reminder->getReminderTime()->format('H:i:s'));
        self::assertSame([], $reminder->getWeekdays());
        self::assertNull($reminder->getScheduledDate());
        self::assertSame('Europe/Paris', $reminder->getTimezone());
        self::assertSame('UTC', $reminder->getCreatedAt()->getTimezone()->getName());
        self::assertSame('UTC', $reminder->getNextRunAt()?->getTimezone()->getName());
    }

    public function testReconfigurationNormalizesValuesAndReactivatesReminder(): void
    {
        $reminder = $this->createDailyReminder();

        $reminder->disable(new \DateTimeImmutable('2026-09-03 09:00:00 UTC'));

        self::assertFalse($reminder->isEnabled());
        self::assertNull($reminder->getNextRunAt());

        $reminder->reconfigure(
            LearningReminderFrequency::WEEKLY,
            $this->time('18:45:00', 'America/Los_Angeles'),
            [5, 1, 5],
            null,
            'Europe/Paris',
            new \DateTimeImmutable('2026-09-04 16:45:00 UTC'),
            new \DateTimeImmutable('2026-09-03 09:30:00 UTC'),
        );

        self::assertTrue($reminder->isEnabled());
        self::assertSame(LearningReminderFrequency::WEEKLY, $reminder->getFrequency());
        self::assertSame('18:45:00', $reminder->getReminderTime()->format('H:i:s'));
        self::assertSame([1, 5], $reminder->getWeekdays());
        self::assertNull($reminder->getScheduledDate());
        self::assertSame(
            '2026-09-04 16:45:00',
            $reminder->getNextRunAt()?->format('Y-m-d H:i:s'),
        );
    }

    public function testReconfigurationPreservesLastSentAt(): void
    {
        $reminder = $this->createDailyReminder();
        $sentAt = new \DateTimeImmutable('2026-09-04 06:00:00 UTC');

        $reminder->markSent(
            $sentAt,
            new \DateTimeImmutable('2026-09-05 06:00:00 UTC'),
            new \DateTimeImmutable('2026-09-04 06:00:01 UTC'),
        );

        $reminder->reconfigure(
            LearningReminderFrequency::DAILY,
            $this->time('10:00:00'),
            [],
            null,
            'Europe/Paris',
            new \DateTimeImmutable('2026-09-05 08:00:00 UTC'),
            new \DateTimeImmutable('2026-09-04 07:00:00 UTC'),
        );

        self::assertEquals($sentAt, $reminder->getLastSentAt());
        self::assertTrue($reminder->isEnabled());
    }

    public function testMarkSentDisablesOnceReminder(): void
    {
        $reminder = $this->createOnceReminder();
        $sentAt = new \DateTimeImmutable('2026-09-04 08:00:00 UTC');

        $reminder->markSent(
            $sentAt,
            null,
            new \DateTimeImmutable('2026-09-04 08:00:01 UTC'),
        );

        self::assertEquals($sentAt, $reminder->getLastSentAt());
        self::assertFalse($reminder->isEnabled());
        self::assertNull($reminder->getNextRunAt());
    }

    public function testMarkSentRequiresAndStoresNextOccurrenceForRecurringReminder(): void
    {
        $reminder = $this->createDailyReminder();
        $nextRunAt = new \DateTimeImmutable('2026-09-05 06:00:00 UTC');

        $reminder->markSent(
            new \DateTimeImmutable('2026-09-04 06:00:00 UTC'),
            $nextRunAt,
            new \DateTimeImmutable('2026-09-04 06:00:01 UTC'),
        );

        self::assertEquals($nextRunAt, $reminder->getNextRunAt());
        self::assertTrue($reminder->isEnabled());
        self::assertNotNull($reminder->getLastSentAt());
    }

    public function testMarkSentRejectsMissingNextOccurrenceForRecurringReminder(): void
    {
        $reminder = $this->createDailyReminder();

        $this->expectException(\InvalidArgumentException::class);

        $reminder->markSent(
            new \DateTimeImmutable('2026-09-04 06:00:00 UTC'),
            null,
            new \DateTimeImmutable('2026-09-04 06:00:01 UTC'),
        );
    }

    public function testScheduleNextRunAtRejectsDisabledReminder(): void
    {
        $reminder = $this->createDailyReminder();
        $reminder->disable(new \DateTimeImmutable('2026-09-03 07:00:00 UTC'));

        $this->expectException(\LogicException::class);

        $reminder->scheduleNextRunAt(
            new \DateTimeImmutable('2026-09-05 06:00:00 UTC'),
            new \DateTimeImmutable('2026-09-03 08:00:00 UTC'),
        );
    }

    public function testUpdatedAtCannotMoveBackwards(): void
    {
        $reminder = $this->createDailyReminder();
        $reminder->scheduleNextRunAt(
            new \DateTimeImmutable('2026-09-05 06:00:00 UTC'),
            new \DateTimeImmutable('2026-09-03 08:00:00 UTC'),
        );

        $this->expectException(\InvalidArgumentException::class);

        $reminder->disable(new \DateTimeImmutable('2026-09-03 07:59:59 UTC'));
    }

    public function testDisableClearsNextRunAndUpdatesTimestamp(): void
    {
        $reminder = $this->createDailyReminder();
        $updatedAt = new \DateTimeImmutable(
            '2026-09-03 12:00:00',
            new \DateTimeZone('Europe/Paris'),
        );

        $reminder->disable($updatedAt);

        self::assertFalse($reminder->isEnabled());
        self::assertNull($reminder->getNextRunAt());
        self::assertSame('2026-09-03 10:00:00', $reminder->getUpdatedAt()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $reminder->getUpdatedAt()->getTimezone()->getName());
    }

    public function testWeeklyFrequencyRequiresAtLeastOneIsoWeekday(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LearningReminder::create(
            new User(),
            LearningReminderFrequency::WEEKLY,
            $this->time('09:00:00'),
            [],
            null,
            'Europe/Paris',
            new \DateTimeImmutable('2026-09-04 07:00:00 UTC'),
            new \DateTimeImmutable('2026-09-03 07:00:00 UTC'),
        );
    }

    public function testNonWeeklyFrequencyRejectsWeekdays(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LearningReminder::create(
            new User(),
            LearningReminderFrequency::DAILY,
            $this->time('09:00:00'),
            [1],
            null,
            'Europe/Paris',
            new \DateTimeImmutable('2026-09-04 07:00:00 UTC'),
            new \DateTimeImmutable('2026-09-03 07:00:00 UTC'),
        );
    }

    public function testOnceFrequencyRequiresScheduledDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LearningReminder::create(
            new User(),
            LearningReminderFrequency::ONCE,
            $this->time('09:00:00'),
            [],
            null,
            'Europe/Paris',
            new \DateTimeImmutable('2026-09-04 07:00:00 UTC'),
            new \DateTimeImmutable('2026-09-03 07:00:00 UTC'),
        );
    }

    public function testNextRunMustBeStrictlyFuture(): void
    {
        $instant = new \DateTimeImmutable('2026-09-03 07:00:00 UTC');

        $this->expectException(\InvalidArgumentException::class);

        LearningReminder::create(
            new User(),
            LearningReminderFrequency::DAILY,
            $this->time('09:00:00'),
            [],
            null,
            'Europe/Paris',
            $instant,
            $instant,
        );
    }

    private function createDailyReminder(): LearningReminder
    {
        return LearningReminder::create(
            new User(),
            LearningReminderFrequency::DAILY,
            $this->time('08:00:00'),
            [],
            null,
            'Europe/Paris',
            new \DateTimeImmutable('2026-09-04 06:00:00 UTC'),
            new \DateTimeImmutable('2026-09-03 06:00:00 UTC'),
        );
    }

    private function createOnceReminder(): LearningReminder
    {
        return LearningReminder::create(
            new User(),
            LearningReminderFrequency::ONCE,
            $this->time('10:00:00'),
            [],
            new \DateTimeImmutable('2026-09-04 UTC'),
            'Europe/Paris',
            new \DateTimeImmutable('2026-09-04 08:00:00 UTC'),
            new \DateTimeImmutable('2026-09-03 06:00:00 UTC'),
        );
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
}
