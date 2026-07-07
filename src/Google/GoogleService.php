<?php

namespace App\Google;

class GoogleService
{
    public function __construct(private readonly string $googleKey)
    {
    }

    public function getGoogleKey(): string
    {
        return $this->googleKey;
    }
}
