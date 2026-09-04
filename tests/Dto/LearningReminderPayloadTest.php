<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\LearningReminderPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class LearningReminderPayloadTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    #[DataProvider('validPayloadProvider')]
    public function testValidPayloads(LearningReminderPayload $payload): void
    {
        self::assertCount(0, $this->validator->validate($payload));
    }

    /**
     * @return iterable<string, array{0: LearningReminderPayload}>
     */
    public static function validPayloadProvider(): iterable
    {
        yield 'daily' => [
            new LearningReminderPayload('daily', '08:30', [], null, 'Europe/Paris'),
        ];

        yield 'weekly' => [
            new LearningReminderPayload('weekly', '18:00', [1, 3, 5], null, 'Europe/Paris'),
        ];

        yield 'once' => [
            new LearningReminderPayload('once', '14:15', [], '2026-10-12', 'Europe/Paris'),
        ];
    }

    #[DataProvider('invalidPayloadProvider')]
    public function testInvalidPayloads(
        LearningReminderPayload $payload,
        string $expectedProperty,
    ): void {
        $violations = $this->validator->validate($payload);

        self::assertGreaterThan(0, $violations->count());
        self::assertTrue($this->hasViolationAt($violations, $expectedProperty));
    }

    /**
     * @return iterable<string, array{0: LearningReminderPayload, 1: string}>
     */
    public static function invalidPayloadProvider(): iterable
    {
        yield 'unknown frequency' => [
            new LearningReminderPayload('monthly', '08:30', [], null, 'Europe/Paris'),
            'frequency',
        ];

        yield 'invalid time' => [
            new LearningReminderPayload('daily', '25:00', [], null, 'Europe/Paris'),
            'reminderTime',
        ];

        yield 'invalid timezone' => [
            new LearningReminderPayload('daily', '08:30', [], null, 'Unknown/Zone'),
            'timezone',
        ];

        yield 'non sequential weekdays' => [
            new LearningReminderPayload('weekly', '08:30', [2 => 1, 4 => 3], null, 'Europe/Paris'),
            'weekdays',
        ];

        yield 'non integer day' => [
            new LearningReminderPayload('weekly', '08:30', ['1'], null, 'Europe/Paris'),
            'weekdays[0]',
        ];

        yield 'day below one' => [
            new LearningReminderPayload('weekly', '08:30', [0], null, 'Europe/Paris'),
            'weekdays[0]',
        ];

        yield 'day above seven' => [
            new LearningReminderPayload('weekly', '08:30', [8], null, 'Europe/Paris'),
            'weekdays[0]',
        ];

        yield 'duplicate day' => [
            new LearningReminderPayload('weekly', '08:30', [1, 1], null, 'Europe/Paris'),
            'weekdays',
        ];

        yield 'empty weekly list' => [
            new LearningReminderPayload('weekly', '08:30', [], null, 'Europe/Paris'),
            'weekdays',
        ];

        yield 'days forbidden for daily' => [
            new LearningReminderPayload('daily', '08:30', [1], null, 'Europe/Paris'),
            'weekdays',
        ];

        yield 'days forbidden for once' => [
            new LearningReminderPayload('once', '08:30', [1], '2026-10-12', 'Europe/Paris'),
            'weekdays',
        ];

        yield 'date absent for once' => [
            new LearningReminderPayload('once', '08:30', [], null, 'Europe/Paris'),
            'scheduledDate',
        ];

        yield 'empty date for once' => [
            new LearningReminderPayload('once', '08:30', [], '', 'Europe/Paris'),
            'scheduledDate',
        ];

        yield 'date forbidden for daily' => [
            new LearningReminderPayload('daily', '08:30', [], '2026-10-12', 'Europe/Paris'),
            'scheduledDate',
        ];

        yield 'date forbidden for weekly' => [
            new LearningReminderPayload('weekly', '08:30', [1], '2026-10-12', 'Europe/Paris'),
            'scheduledDate',
        ];

        yield 'invalid date format' => [
            new LearningReminderPayload('once', '08:30', [], '12/10/2026', 'Europe/Paris'),
            'scheduledDate',
        ];
    }

    private function hasViolationAt(
        ConstraintViolationListInterface $violations,
        string $propertyPath,
    ): bool {
        return array_any(
            iterator_to_array($violations),
            static fn (ConstraintViolationInterface $violation): bool => $violation->getPropertyPath() === $propertyPath,
        );
    }
}
