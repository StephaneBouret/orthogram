<?php

namespace App\Enum;

enum SubscriptionStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';
    case SUSPENDED = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::ACTIVE => 'Active',
            self::EXPIRED => 'Expirée',
            self::CANCELLED => 'Annulée',
            self::SUSPENDED => 'Suspendue',
        };
    }

    /**
     * @return array<string, self>
     */
    public static function choices(): array
    {
        return [
            'En attente' => self::PENDING,
            'Active' => self::ACTIVE,
            'Expirée' => self::EXPIRED,
            'Annulée' => self::CANCELLED,
            'Suspendue' => self::SUSPENDED,
        ];
    }
}
