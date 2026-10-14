<?php

declare(strict_types=1);

namespace App\Services\Dictation;

/** Pedagogical rules approved by Caroline and Stéphane, including elision units. */
final class CorrectionContract
{
    public const VERSION = 'orthogram-dictation-v1';
    public const MAX_REFERENCE_WORDS = 500;
    public const MAX_COPY_WORDS = 750;
    public const MAX_UTF16_LENGTH = 20000;
    public const MAX_VARIANT_PASSAGES = 32;
    public const MAX_ALTERNATIVES = 4;
    public const MAX_VARIANT_LENGTH = 128;
    public const MAX_VARIANTS_TOTAL_LENGTH = 4096;
}
