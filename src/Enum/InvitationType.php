<?php

namespace App\Enum;

enum InvitationType: string
{
    case FREE_YEAR = 'free_year';
    case LIFETIME = 'lifetime';

    public function label(): string
    {
        return match ($this) {
            self::FREE_YEAR => '1 an gratuit',
            self::LIFETIME => 'Accès à vie',
        };
    }

    public function subscriptionTitle(): string
    {
        return match ($this) {
            self::FREE_YEAR => 'Abonnement praticien offert - 1 an',
            self::LIFETIME => 'Abonnement praticien offert - à vie',
        };
    }

    public function subscriptionDescription(): string
    {
        return match ($this) {
            self::FREE_YEAR => 'Accès offert pendant 1 an à l\'espace praticien, incluant les calculs supplémentaires et la génération des rapports.',
            self::LIFETIME => 'Accès offert à vie à l\'espace praticien, incluant les calculs supplémentaires et la génération des rapports.',
        };
    }
}
