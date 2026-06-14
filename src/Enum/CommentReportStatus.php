<?php

namespace App\Enum;

enum CommentReportStatus: string
{
    case PENDING = 'pending';
    case REVIEWED = 'reviewed';
    case DISMISSED = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'À traiter',
            self::REVIEWED => 'Traité',
            self::DISMISSED => 'Rejeté',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::REVIEWED => 'success',
            self::DISMISSED => 'secondary',
        };
    }
}
