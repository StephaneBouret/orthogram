<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Dictation;
use App\Entity\DictationAttempt;
use App\Entity\DictationVersion;
use App\Kernel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Version\DbalMigrationFactory;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use Psr\Log\NullLogger;

/** Creates/drops only its own random local database. Never connects to the configured application database. */
final class DictationTestDatabase
{
    private Kernel $kernel;
    private Connection $server;
    private EntityManagerInterface $baseManager;
    private bool $created = false;
    /** @var array<string, mixed> */
    private array $params;
    /** @var list<Connection> */
    private array $connections = [];
    public readonly string $name;

    public function __construct(?string $existingWorkerDatabase = null)
    {
        $this->name = $existingWorkerDatabase ?? 'orthogram_dictation_2a_'.bin2hex(random_bytes(8)).'_test';
        if (1 !== preg_match('/^orthogram_dictation_2a_[a-f0-9]{16}_test$/D', $this->name)) {
            throw new \LogicException('Base de test isolée requise.');
        }
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $registry = $this->kernel->getContainer()->get('doctrine');
        $params = $registry->getConnection()->getParams();
        if ('pdo_mysql' !== ($params['driver'] ?? '') || !in_array($params['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new \LogicException('MySQL local requis pour cette recette.');
        }
        unset($params['dbname'], $params['url']);
        $this->server = DriverManager::getConnection($params);
        $this->params = $params + ['dbname' => $this->name];
        $base = $registry->getManager();
        if (!$base instanceof EntityManagerInterface) {
            throw new \LogicException('Doctrine ORM requis.');
        }
        $this->baseManager = $base;
        if (null === $existingWorkerDatabase) {
            $this->server->executeStatement('CREATE DATABASE '.$this->server->quoteIdentifier($this->name).' CHARACTER SET utf8mb4');
            $this->created = true;
        }
    }

    public function manager(): EntityManagerInterface
    {
        $connection = DriverManager::getConnection($this->params);
        if ($connection->getDatabase() !== $this->name) {
            throw new \LogicException('Connexion non isolée.');
        }
        $this->connections[] = $connection;

        return new EntityManager($connection, $this->baseManager->getConfiguration(), $this->baseManager->getEventManager());
    }

    public function install(): void
    {
        if (!$this->created) {
            throw new \LogicException('Seul le créateur peut installer le schéma.');
        }
        $em = $this->manager();
        $errors = (new SchemaValidator($em))->validateMapping();
        if ([] !== $errors) {
            throw new \RuntimeException(json_encode($errors, JSON_THROW_ON_ERROR));
        }
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $new = [Dictation::class, DictationVersion::class, DictationAttempt::class];
        (new SchemaTool($em))->createSchema(array_values(array_filter($metadata, static fn ($m) => !in_array($m->name, $new, true))));
        $this->migration($em->getConnection(), true);
        $changes = (new SchemaTool($em))->getUpdateSchemaSql($metadata);
        if ([] !== $changes) {
            throw new \RuntimeException('Migration et mapping divergent : '.implode('; ', $changes));
        }
    }

    public function migration(Connection $connection, bool $up): void
    {
        if ($connection->getDatabase() !== $this->name) {
            throw new \LogicException('Migration autorisée uniquement sur la base isolée.');
        }
        require_once dirname(__DIR__, 2).'/migrations/Version20261016120000.php';
        $migration = (new DbalMigrationFactory($connection, new NullLogger()))->createVersion('DoctrineMigrations\\Version20261016120000');
        $up ? $migration->up(new Schema()) : $migration->down(new Schema());
        foreach ($migration->getSql() as $sql) {
            $connection->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        }
    }

    public function close(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        if ($this->created) {
            $this->server->executeStatement('DROP DATABASE '.$this->server->quoteIdentifier($this->name));
            $this->created = false;
        }
        $this->server->close();
        $this->kernel->shutdown();
    }
}
