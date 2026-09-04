<?php

declare(strict_types=1);

namespace App\Services;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class LearningReminderDispatchLock
{
    private const MAX_NAME_LENGTH = 64;

    private readonly string $name;
    private bool $acquired = false;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.environment%')]
        string $environment,
    ) {
        $this->name = sprintf('orthogram.%s.learning-reminders.dispatch', $environment);

        if (\strlen($this->name) > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException('Le nom du verrou MySQL dépasse 64 caractères.');
        }
    }

    public function acquire(): bool
    {
        if ($this->acquired) {
            return true;
        }

        $result = $this->connection->fetchOne(
            'SELECT GET_LOCK(:name, 0)',
            ['name' => $this->name],
        );

        if (1 === $result || '1' === $result) {
            $this->acquired = true;

            return true;
        }

        if (0 === $result || '0' === $result || null === $result) {
            return false;
        }

        throw new \UnexpectedValueException('MySQL a retourné une valeur inattendue lors de l’acquisition du verrou.');
    }

    public function release(): bool
    {
        if (!$this->acquired) {
            return true;
        }

        $result = $this->connection->fetchOne(
            'SELECT RELEASE_LOCK(:name)',
            ['name' => $this->name],
        );

        $this->acquired = false;

        return 1 === $result || '1' === $result;
    }
}
