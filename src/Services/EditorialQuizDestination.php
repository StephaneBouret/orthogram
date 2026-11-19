<?php

namespace App\Services;

use Doctrine\DBAL\Connection;

interface EditorialQuizDestination
{
    /** @param array<string, mixed> $options
     * @return array{environment: string, database: string, host: string, port: int, server: string} */
    public function verify(Connection $db, array $options, string $action = 'import'): array;
}
