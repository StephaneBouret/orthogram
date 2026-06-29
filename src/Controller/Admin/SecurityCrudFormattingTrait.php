<?php

declare(strict_types=1);

namespace App\Controller\Admin;

trait SecurityCrudFormattingTrait
{
    private const APP_TIMEZONE = 'Europe/Paris';

    private function formatMonospaceValue(mixed $value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        return sprintf('<code>%s</code>', $this->escapeHtml((string) $value));
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
