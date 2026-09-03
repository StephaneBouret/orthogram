<?php

declare(strict_types=1);

namespace App\Tests\Doctrine\Type;

use App\Doctrine\Type\UtcDateTimeImmutableType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use PHPUnit\Framework\TestCase;

final class UtcDateTimeImmutableTypeTest extends TestCase
{
    private UtcDateTimeImmutableType $type;
    private SQLitePlatform $platform;

    protected function setUp(): void
    {
        $this->type = new UtcDateTimeImmutableType();
        $this->platform = new SQLitePlatform();
    }

    public function testConvertsImmutableDateTimeToUtcForStorage(): void
    {
        $value = new \DateTimeImmutable(
            '2026-09-03 10:15:30',
            new \DateTimeZone('Europe/Paris'),
        );

        $databaseValue = $this->type->convertToDatabaseValue($value, $this->platform);

        self::assertSame('2026-09-03 08:15:30', $databaseValue);
        self::assertSame('Europe/Paris', $value->getTimezone()->getName());
        self::assertSame('2026-09-03 10:15:30', $value->format('Y-m-d H:i:s'));
    }

    public function testConvertsDatabaseValueToExplicitUtc(): void
    {
        $value = $this->type->convertToPHPValue('2026-09-03 08:15:30', $this->platform);

        self::assertInstanceOf(\DateTimeImmutable::class, $value);
        self::assertSame('2026-09-03 08:15:30', $value->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $value->getTimezone()->getName());
    }

    public function testNormalizesImmutableDatabaseValueToUtc(): void
    {
        $databaseValue = new \DateTimeImmutable(
            '2026-09-03 10:15:30',
            new \DateTimeZone('Europe/Paris'),
        );

        $value = $this->type->convertToPHPValue($databaseValue, $this->platform);

        self::assertSame('2026-09-03 08:15:30', $value->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $value->getTimezone()->getName());
        self::assertSame('Europe/Paris', $databaseValue->getTimezone()->getName());
    }

    public function testNullIsPreservedInBothDirections(): void
    {
        // Le contrat de conversion null est volontairement testé.
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        // Le contrat de conversion null est volontairement testé.
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function testMutableDateTimeIsRejectedForStorage(): void
    {
        $this->expectException(InvalidType::class);

        $this->type->convertToDatabaseValue(
            new \DateTime('2026-09-03 08:15:30 UTC'),
            $this->platform,
        );
    }

    public function testIncorrectDatabasePhpTypeIsRejected(): void
    {
        $this->expectException(InvalidType::class);

        $this->type->convertToPHPValue(123, $this->platform);
    }

    public function testIncorrectDatabaseFormatIsRejected(): void
    {
        $this->expectException(InvalidFormat::class);

        $this->type->convertToPHPValue('not-a-date', $this->platform);
    }
}
