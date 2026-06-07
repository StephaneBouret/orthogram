<?php

namespace App\Enum;

enum UserAccountStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case SUSPENDED = 'suspended';
    case PENDING_VERIFICATION = 'pending_verification';
    case DELETED = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Actif',
            self::INACTIVE => 'Inactif',
            self::SUSPENDED => 'Suspendu',
            self::PENDING_VERIFICATION => 'En attente de vérification',
            self::DELETED => 'Supprimé',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::ACTIVE => 'bg-success',
            self::INACTIVE => 'bg-secondary',
            self::SUSPENDED => 'bg-danger',
            self::PENDING_VERIFICATION => 'bg-warning text-dark',
            self::DELETED => 'bg-dark',
        };
    }

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }
}
