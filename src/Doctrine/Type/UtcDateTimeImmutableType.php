<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;

final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    public const NAME = 'utc_datetime_immutable';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof \DateTimeImmutable) {
            throw InvalidType::new($value, self::class, ['null', \DateTimeImmutable::class]);
        }

        return $value
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format($platform->getDateTimeFormatString());
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value->setTimezone(new \DateTimeZone('UTC'));
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::class, ['null', 'string', \DateTimeImmutable::class]);
        }

        $format = $platform->getDateTimeFormatString();
        $dateTime = \DateTimeImmutable::createFromFormat(
            '!'.$format,
            $value,
            new \DateTimeZone('UTC'),
        );
        $errors = \DateTimeImmutable::getLastErrors();

        if (
            false === $dateTime
            || (
                false !== $errors
                && (
                    $errors['warning_count'] > 0
                    || $errors['error_count'] > 0
                )
            )
        ) {
            throw InvalidFormat::new($value, self::class, $format);
        }

        return $dateTime->setTimezone(new \DateTimeZone('UTC'));
    }
}
