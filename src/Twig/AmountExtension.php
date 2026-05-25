<?php

namespace App\Twig;

use Twig\Attribute\AsTwigFilter;

final class AmountExtension
{
    #[AsTwigFilter('amount')]
    public function formatAmount(
        int|float|null $amount,
        int $divisor = 100,
        int $decimals = 2,
        string $decimalSeparator = ',',
        string $thousandsSeparator = ' ',
        string $suffix = ' €'
    ): string {
        if (null === $amount) {
            return '0' . $suffix;
        }

        $value = $amount / $divisor;

        return number_format($value, $decimals, $decimalSeparator, $thousandsSeparator) . $suffix;
    }
}
