<?php

namespace App\Enum;

enum DeviceStatus: string
{
    case ACTIVE = 'active';
    case REVOKED = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Actif',
            self::REVOKED => 'Révoqué',
        };
    }

    public static function choices(): array
    {
        return [
            'Actif' => self::ACTIVE,
            'Révoqué' => self::REVOKED,
        ];
    }
}
