<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\DictationFactory;
use App\Tests\Support\DictationTestDatabase;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Version\DbalMigrationFactory;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DictationEnrollmentMigrationTest extends TestCase
{
    public function testMigrationPreservesExistingUsersWithoutInventingAHistory(): void
    {
        $database = new DictationTestDatabase();
        try {
            $database->install();
            $em = $database->manager();
            [$user] = DictationFactory::create($em);
            $connection = $em->getConnection();
            self::assertSame($database->name, $connection->getDatabase());
            require_once dirname(__DIR__, 2).'/migrations/Version20261017180000.php';
            $factory = new DbalMigrationFactory($connection, new NullLogger());
            $down = $factory->createVersion(\DoctrineMigrations\Version20261017180000::class);
            $down->down(new Schema());
            foreach ($down->getSql() as $sql) {
                $connection->executeStatement($sql->getStatement());
            }
            $before = $connection->fetchAllAssociative('SELECT * FROM user');
            $up = $factory->createVersion(\DoctrineMigrations\Version20261017180000::class);
            $up->up(new Schema());
            foreach ($up->getSql() as $sql) {
                $connection->executeStatement($sql->getStatement());
            }
            $after = $connection->fetchAllAssociative('SELECT * FROM user');
            foreach ($after as &$row) {
                self::assertNull($row['dictation_prerequisites']);
                unset($row['dictation_prerequisites']);
            }
            self::assertSame($before, $after);
            self::assertSame([], (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata()));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM lesson WHERE user_id = ?', [$user->getId()]));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM dictation_attempt WHERE user_id = ?', [$user->getId()]));
        } finally {
            $database->close();
        }
    }
}
