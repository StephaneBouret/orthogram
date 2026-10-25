<?php

namespace App\Exception;

final class InvalidQuizConfiguration extends \DomainException
{
    public function __construct(string $message, public readonly ?string $propertyPath = null, public readonly ?int $questionId = null)
    {
        parent::__construct($message);
    }
}
