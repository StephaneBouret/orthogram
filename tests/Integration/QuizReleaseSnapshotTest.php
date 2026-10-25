<?php

namespace App\Tests\Integration;

use App\Services\ModuleOneReserveImporter;
use App\Services\ModuleOneReserveManifest;
use App\Services\QuizConfigurationValidator;
use App\Services\QuizEditorialService;
use App\Services\QuizReleaseSnapshot;
use App\Tests\Support\DictationTestDatabase;
use App\Tests\Support\ModuleOneReserveFixture;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Version\DbalMigrationFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;
use Symfony\Component\Validator\Validation;

final class QuizReleaseSnapshotTest extends TestCase
{
    public function testRealMigrationsImportActivationAndUnexpectedChanges(): void
    {
        $database = new DictationTestDatabase();
        try {
            $database->install();
            $em = $database->manager();
            $db = $em->getConnection();
            $manifest = new ModuleOneReserveManifest(dirname(__DIR__, 2).'/resources/quiz/module1_reserve.json');
            ModuleOneReserveFixture::create($em, $manifest);
            $db->executeStatement('DROP TABLE quiz_reserve_import');
            $db->executeStatement('ALTER TABLE quiz DROP renewal_enabled, DROP error_question_quota');
            $db->executeStatement('ALTER TABLE quiz_question DROP reserve, DROP contains_error');
            $db->executeStatement('CREATE TABLE doctrine_migration_versions (version VARCHAR(191) NOT NULL PRIMARY KEY, executed_at DATETIME DEFAULT NULL, execution_time INT DEFAULT NULL)');
            $audit = new QuizReleaseSnapshot();
            $before = $audit->capture($db);
            self::assertSame($before, $audit->capture($db));
            foreach (['Version20261025120000', 'Version20261025150000'] as $name) {
                require_once dirname(__DIR__, 2).'/migrations/'.$name.'.php';
                $migration = (new DbalMigrationFactory($db, new NullLogger()))->createVersion('DoctrineMigrations\\'.$name);
                $migration->up(new Schema());
                foreach ($migration->getSql() as $query) {
                    $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
                }
                $db->insert('doctrine_migration_versions', ['version' => 'DoctrineMigrations\\'.$name]);
            }
            $migrated = $audit->capture($db);
            self::assertTrue($audit->compare($before, $migrated, 'migration')['success']);
            self::assertTrue($audit->compare($migrated, $migrated, 'migration')['success']);
            $em->clear();
            $validator = new QuizConfigurationValidator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
            $importer = new ModuleOneReserveImporter($em, new QuizEditorialService($em, $validator), $validator, $manifest);
            $plan = $importer->plan();
            $importer->apply('import', $plan['plan_hash']);
            $imported = $audit->capture($db);
            self::assertTrue($audit->compare($migrated, $imported, 'import', $plan)['success']);
            $enable = $importer->plan('enable');
            $importer->apply('enable', $enable['plan_hash']);
            $enabled = $audit->capture($db);
            self::assertCount(4, $audit->compare($imported, $enabled, 'enable', $enable)['changes']);
            self::assertTrue($audit->compare($enabled, $enabled, 'identical')['success']);
            $directory = sys_get_temp_dir().'/orthogram-quiz-release-'.bin2hex(random_bytes(8));
            mkdir($directory);
            try {
                $destination = ['environment' => 'prod', 'database' => $database->name];
                foreach (['before' => ['destination' => $destination, 'tables' => $imported], 'after' => ['destination' => $destination, 'tables' => $enabled],
                    'plan' => $enable + ['destination' => $destination]] as $name => $payload) {
                    file_put_contents($directory.'/'.$name.'.json', json_encode($payload, JSON_THROW_ON_ERROR));
                }
                $process = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', 'ops/quiz_release_snapshot.php', '--before='.$directory.'/before.json',
                    '--after='.$directory.'/after.json', '--plan='.$directory.'/plan.json', '--phase=enable', '--output='.$directory.'/result.json'], dirname(__DIR__, 2));
                self::assertSame(0, $process->run(), $process->getErrorOutput());
                $result = json_decode(file_get_contents($directory.'/result.json'), true, 512, JSON_THROW_ON_ERROR);
                self::assertTrue($result['success']);
                self::assertCount(4, $result['changes']);
            } finally {
                foreach (['before', 'after', 'plan', 'result'] as $name) {
                    if (is_file($directory.'/'.$name.'.json')) {
                        unlink($directory.'/'.$name.'.json');
                    }
                }
                rmdir($directory);
            }
            foreach (['user', 'quiz_question', 'quiz_answer', 'doctrine_migration_versions'] as $table) {
                $changed = $enabled;
                $changed[$table]['rows'][0]['unexpected'] = 'divergence';
                try {
                    $audit->compare($imported, $changed, 'enable', $enable);
                    self::fail('Difference must stop comparison');
                } catch (\DomainException $error) {
                    self::assertNotSame('', $error->getMessage());
                }
            }
            $changed = $enabled;
            $changed['quiz']['ddl'] .= ' unexpected';
            $this->expectException(\DomainException::class);
            $audit->compare($imported, $changed, 'enable', $enable);
        } finally {
            $database->close();
        }
    }
}
