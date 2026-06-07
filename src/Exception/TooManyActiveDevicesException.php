<?php

namespace App\Exception;

final class TooManyActiveDevicesException extends \RuntimeException
{
    public function __construct(int $maxActiveDevices)
    {
        parent::__construct(sprintf('La limite de %d appareils actifs est atteinte.', $maxActiveDevices));
    }
}
