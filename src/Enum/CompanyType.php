<?php

namespace App\Enum;

enum CompanyType: string
{
    case Association = 'Association';
    case Micro = 'Micro-entrepreneur';
    case Eurl = 'EURL';
    case Sarl = 'SARL';
    case Sasu = 'SASU';
    case Sas = 'SAS';

    public function label(): string
    {
        return $this->value;
    }

    public static function choices(): array
    {
        $choices = [];

        foreach (self::cases() as $case) {
            $choices[$case->label()] = $case;
        }

        return $choices;
    }
}
