<?php

namespace App\Tests\Integration;

use App\Command\ModuleTwoQuizImportCommand;
use App\Command\ModuleTwoQuizRenewalCommand;
use App\Entity\Courses;
use App\Entity\Quiz;
use App\Entity\QuizAttempt;
use App\Entity\QuizReserveImport;
use App\Services\ModuleOneReserveManifest;
use App\Services\ModuleTwoQuizDestination;
use App\Services\ModuleTwoQuizImporter;
use App\Services\ModuleTwoQuizManifest;
use App\Services\ModuleTwoQuizSnapshot;
use App\Services\QuizAttemptService;
use App\Services\QuizCommandDestination;
use App\Services\QuizConfigurationValidator;
use App\Services\QuizCorrectionService;
use App\Services\QuizEditorialService;
use App\Services\QuizQuestionSelector;
use App\Services\QuizReleaseSnapshot;
use App\Tests\Support\DictationFactory;
use App\Tests\Support\DictationTestDatabase;
use App\Tests\Support\ModuleOneReserveFixture;
use App\Tests\Support\QuizFactory;
use App\Tests\Support\QuizPlayerFactory;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Version\DbalMigrationFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;
use Symfony\Component\Validator\Validation;

final class ModuleTwoQuizImportTest extends TestCase
{
    private DictationTestDatabase $database;
    private EntityManagerInterface $em;
    private ModuleTwoQuizManifest $manifest;
    private ModuleTwoQuizImporter $importer;
    private ModuleTwoQuizDestination $destination;
    private QuizAttemptService $attempts;
    /** @var array<string, mixed> */
    private array $context;

    protected function setUp(): void
    {
        $this->database = new DictationTestDatabase(recipe: 'module2');
        try {
            $this->database->install();
        } catch (\Throwable $error) {
            $this->database->close();
            throw $error;
        }
        $this->em = $this->database->manager();
        $this->manifest = new ModuleTwoQuizManifest(dirname(__DIR__, 2).'/resources/quiz/module2/module2_quiz_manifest.json');
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $configuration = new QuizConfigurationValidator($validator);
        $this->destination = new ModuleTwoQuizDestination(new QuizCommandDestination('test'));
        $this->importer = new ModuleTwoQuizImporter($this->em, $this->manifest, $configuration, new QuizEditorialService($this->em, $configuration), $this->destination);
        $this->attempts = new QuizAttemptService($this->em, new QuizCorrectionService($validator), new QuizQuestionSelector($configuration));
        $this->context = $this->destination->verify($this->em->getConnection(), ['expect-database' => $this->database->name]);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->database->close();
        unset($this->em, $this->database, $this->importer, $this->attempts);
        gc_collect_cycles();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function rows(): array
    {
        $db = $this->em->getConnection();
        $tables = $db->createSchemaManager()->listTableNames();
        sort($tables);
        $rows = [];
        foreach ($tables as $table) {
            $data = $db->fetchAllAssociative('SELECT * FROM '.$db->quoteIdentifier($table));
            usort($data, static fn ($a, $b) => json_encode($a) <=> json_encode($b));
            $rows[$table] = $data;
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function apply(string $action = 'import'): array
    {
        $plan = $this->importer->plan($action, $this->context);
        self::assertSame([], $plan['errors']);

        return $this->importer->apply($action, $plan['plan_hash'], $this->context);
    }

    /** @return array<string, string> */
    private function options(): array
    {
        $options = [];
        foreach (['environment' => 'env', 'database' => 'database', 'host' => 'host', 'port' => 'port', 'server' => 'server'] as $field => $option) {
            $options['--expect-'.$option] = (string) $this->context[$field];
        }

        return $options;
    }

    public function testOfflineConservationChecksRejectAnyUnrelatedChange(): void
    {
        [$user, $course] = QuizPlayerFactory::create($this->em);
        $this->attempts->mutate($user, $course, 'start', []);
        $capture = fn () => ['destination' => $this->context, 'tables' => (new QuizReleaseSnapshot())->capture($this->em->getConnection())];
        $audit = new ModuleTwoQuizSnapshot();
        $before = $capture();
        $this->apply();
        $after = $capture();
        $plan = $this->importer->plan('import', $this->context);
        self::assertSame(['quiz' => 3, 'quiz_question' => 177, 'quiz_answer' => 354, 'quiz_reserve_import' => 177, 'quiz_manifest_identity' => 3], $audit->compare($before, $after, 'import', $plan)['changes']);
        self::assertTrue($audit->compare($after, $after, 'identical')['success']);
        self::assertSame(0, $audit->compare($after, $after, 'import', $plan)['changes']['quiz']);
        foreach (array_keys($after['tables']) as $table) {
            $corrupt = $after;
            $corrupt['tables'][$table]['rows'][] = ['unexpected' => 'row'];
            try {
                $audit->compare($after, $corrupt, 'identical');
                self::fail('Unexpected change accepted: '.$table);
            } catch (\DomainException $error) {
                self::assertStringContainsString($table, $error->getMessage());
            }
        }
        $activation = $this->importer->plan('enable', $this->context);
        $this->apply('enable');
        $enabled = $capture();
        self::assertCount(3, $audit->compare($after, $enabled, 'enable', $activation)['changes']);
        $corrupt = $enabled;
        $corrupt['tables']['quiz']['rows'][0]['error_question_quota'] = 1;
        try {
            $audit->compare($after, $corrupt, 'enable', $activation);
            self::fail('Quota change accepted');
        } catch (\DomainException $error) {
            self::assertStringContainsString('quiz', $error->getMessage());
        }
        $disable = $this->importer->plan('disable', $this->context);
        $this->apply('disable');
        self::assertCount(3, $audit->compare($enabled, $capture(), 'disable', $disable)['changes']);
        self::assertTrue($audit->compare($after, $capture(), 'identical')['success']);
        $file = tempnam(sys_get_temp_dir(), 'module2-audit-');
        try {
            file_put_contents($file, json_encode($after, JSON_THROW_ON_ERROR));
            $cli = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', dirname(__DIR__, 2).'/ops/module2_quiz_compare.php', '--phase=identical', '--before='.$file, '--after='.$file, '--output='.$file.'.result']);
            self::assertSame(0, $cli->run(), $cli->getErrorOutput());
            self::assertTrue(json_decode(file_get_contents($file.'.result'), true, 512, JSON_THROW_ON_ERROR)['success']);
            // A rerun cannot overwrite operational evidence.
            self::assertSame(1, $cli->run());
        } finally {
            unlink($file);
            if (is_file($file.'.result')) {
                unlink($file.'.result');
            }
        }
        // Actual registry DDL is covered separately; verify the migration journal allowance.
        $oldSchema = $after;
        unset($oldSchema['tables']['quiz_manifest_identity']);
        $newSchema = $oldSchema;
        $newSchema['tables']['quiz_manifest_identity'] = $after['tables']['quiz_manifest_identity'];
        $newSchema['tables']['quiz_manifest_identity']['rows'] = [];
        $oldSchema['tables']['doctrine_migration_versions'] = ['rows' => [], 'columns' => [], 'ddl' => 'journal'];
        $newSchema['tables']['doctrine_migration_versions'] = ['rows' => [['version' => 'DoctrineMigrations\\Version20261027120000']], 'columns' => [], 'ddl' => 'journal'];
        ksort($oldSchema['tables']);
        ksort($newSchema['tables']);
        self::assertTrue($audit->compare($oldSchema, $newSchema, 'migration')['success']);
        $newSchema['tables']['doctrine_migration_versions']['rows'][] = ['version' => 'unexpected migration'];
        $this->expectException(\DomainException::class);
        $audit->compare($oldSchema, $newSchema, 'migration');
    }

    public function testConsentOptionOnDisposableDatabasePreservesDestinationAndPlanChecks(): void
    {
        $tester = new CommandTester(new ModuleTwoQuizImportCommand($this->importer, $this->em, $this->destination));
        $before = $this->rows();
        self::assertSame(0, $tester->execute($this->options() + ['--allow-work-database' => true]));
        $plan = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($before, $this->rows());
        $apply = $this->options() + ['--apply' => true, '--allow-work-database' => true, '--expect-plan' => $plan['plan_hash']];
        self::assertSame(1, $tester->execute(array_replace($apply, ['--expect-database' => 'orthogram'])));
        $production = new ModuleTwoQuizDestination(new QuizCommandDestination('prod'));
        $prodTester = new CommandTester(new ModuleTwoQuizImportCommand($this->importer, $this->em, $production));
        self::assertSame(1, $prodTester->execute(array_replace($apply, ['--expect-env' => 'prod'])));
        self::assertSame($before, $this->rows());
        self::assertSame(0, $tester->execute($apply));
        $after = $this->rows();
        self::assertSame(1, $tester->execute($apply));
        self::assertStringContainsString('périmé', $tester->getDisplay());
        self::assertSame($after, $this->rows());
        self::assertSame(0, $tester->execute($this->options()));
        $next = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $tester->execute(array_replace($apply, ['--expect-plan' => $next['plan_hash']])));
        self::assertSame($after, $this->rows());
    }

    public function testProductionCommandsOnDisposableDatabaseRequireConsentForEveryOperation(): void
    {
        $configuration = new QuizConfigurationValidator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
        $this->destination = new ModuleTwoQuizDestination(new QuizCommandDestination('prod'));
        $this->importer = new ModuleTwoQuizImporter($this->em, $this->manifest, $configuration, new QuizEditorialService($this->em, $configuration), $this->destination);
        $this->context['environment'] = 'prod';
        $before = $this->rows();
        $import = new CommandTester(new ModuleTwoQuizImportCommand($this->importer, $this->em, $this->destination));
        $renewal = new CommandTester(new ModuleTwoQuizRenewalCommand($this->importer, $this->em, $this->destination));
        self::assertSame(1, $renewal->execute($this->options() + ['mode' => 'enable']));
        self::assertSame($before, $this->rows());
        foreach (['import', 'enable', 'disable'] as $action) {
            $tester = 'import' === $action ? $import : $renewal;
            $options = $this->options() + ('import' === $action ? [] : ['mode' => $action]);
            $before = $this->rows();
            self::assertSame(0, $tester->execute($options));
            $plan = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($before, $this->rows());
            $apply = $options + ['--apply' => true, '--expect-plan' => $plan['plan_hash']];
            self::assertSame(1, $tester->execute($apply));
            self::assertSame(1, $tester->execute($apply + ['--allow-work-database' => true]));
            self::assertSame(1, $tester->execute(array_replace($apply, ['--expect-server' => 'wrong', '--allow-production-database' => true])));
            // Direct service calls must also recheck consent, not just the CLI.
            try {
                $this->importer->apply($action, $plan['plan_hash'], $this->context);
                self::fail('Consent must reach the service');
            } catch (\DomainException $error) {
                self::assertStringContainsString('--allow-production-database', $error->getMessage());
            }
            self::assertSame($before, $this->rows());
            $apply['--allow-production-database'] = true;
            self::assertSame(0, $tester->execute($apply));
            $after = $this->rows();
            self::assertSame(1, $tester->execute($apply));
            self::assertStringContainsString('périmé', $tester->getDisplay());
            self::assertSame($after, $this->rows());
            self::assertSame(0, $tester->execute($options));
            $next = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            foreach ($next['quizzes'] as $quiz) {
                self::assertSame('enable' === $action, $quiz['renewal_enabled']);
                self::assertNull($quiz['error_question_quota']);
            }
            self::assertSame(0, $tester->execute(array_replace($apply, ['--expect-plan' => $next['plan_hash']])));
            self::assertTrue(json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR)['noop']);
            self::assertSame($after, $this->rows());
        }
    }

    public function testRenewalCycleChangesOnlyThreeFlagsAndPreservesAllLearnerData(): void
    {
        $fixture = ModuleOneReserveFixture::create($this->em, new ModuleOneReserveManifest(dirname(__DIR__, 2).'/resources/quiz/module1_reserve.json'));
        $user = $fixture['user'];
        $template = $fixture['courses']['module1_test1'];
        $fixture['quizzes']['module1_test1']->setRenewalEnabled(true);
        $this->em->flush();
        $oldAttempt = $this->attempts->mutate($user, $template, 'start', []);
        $this->finish($user, $template, $this->em->find(QuizAttempt::class, $oldAttempt['attemptId']));
        $this->apply();
        $imported = $this->importer->plan('import', $this->context);
        $targetIds = [];
        foreach ($this->manifest->data['quizzes'] as $spec) {
            $key = $spec['quiz_key'];
            $quiz = $this->em->find(Quiz::class, $imported['quizzes'][$key]['id']);
            $targetIds[] = $quiz->getId();
            if ('module2_test1' === $key) {
                $quiz->setErrorQuestionQuota(7);
            }
            $course = (new Courses())->setName($key)->setSlug($key)->setSection($template->getSection())->setContentType($template->getContentType())->setQuiz($quiz);
            $this->em->persist($course);
            $this->em->flush();
            $first = $this->attempts->mutate($user, $course, 'start', []);
            $this->finish($user, $course, $this->em->find(QuizAttempt::class, $first['attemptId']));
            $this->attempts->mutate($user, $course, 'restart', ['attemptId' => $first['attemptId']]);
        }
        $before = $this->rows();
        $tester = new CommandTester(new ModuleTwoQuizRenewalCommand($this->importer, $this->em, $this->destination));
        self::assertSame(0, $tester->execute($this->options() + ['mode' => 'enable']));
        $plan = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(3, $plan['renewal_changes']);
        self::assertSame($targetIds, array_column($plan['renewal_changes'], 'quiz_id'));
        self::assertSame([false, false, false], array_column($plan['renewal_changes'], 'before'));
        self::assertSame([true, true, true], array_column($plan['renewal_changes'], 'after'));
        self::assertSame($before, $this->rows());
        $options = $this->options() + ['mode' => 'enable', '--apply' => true, '--allow-work-database' => true, '--expect-plan' => $plan['plan_hash']];
        self::assertSame(1, $tester->execute(array_replace($options, ['--expect-database' => 'orthogram'])));
        $prodTester = new CommandTester(new ModuleTwoQuizRenewalCommand($this->importer, $this->em, new ModuleTwoQuizDestination(new QuizCommandDestination('prod'))));
        self::assertSame(1, $prodTester->execute(array_replace($options, ['--expect-env' => 'prod'])));
        self::assertSame($before, $this->rows());
        self::assertSame(0, $tester->execute($options));
        self::assertSame(3, json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR)['quizzes_changed']);
        $expected = $before;
        foreach ($expected['quiz'] as &$row) {
            if (in_array($row['id'], $targetIds, true)) {
                $row['renewal_enabled'] = 1;
            }
        }
        unset($row);
        self::assertSame($expected, $this->rows());
        self::assertSame(1, $tester->execute($options));
        self::assertStringContainsString('périmé', $tester->getDisplay());
        self::assertSame($expected, $this->rows());
        $next = $this->importer->plan('enable', $this->context);
        self::assertSame([], $next['renewal_changes']);
        self::assertSame(0, $tester->execute(array_replace($options, ['--expect-plan' => $next['plan_hash']])));
        $noop = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($noop['noop']);
        self::assertSame(0, $noop['quizzes_changed']);
        self::assertSame($expected, $this->rows());
        self::assertSame(3, $this->apply('disable')['quizzes_changed']);
        self::assertSame($before, $this->rows());
        self::assertTrue($this->apply('disable')['noop']);
        self::assertSame($before, $this->rows());
    }

    public function testMigrationPreviewImportNoopAndConservation(): void
    {
        $db = $this->em->getConnection();
        [$user, $course] = QuizPlayerFactory::create($this->em);
        DictationFactory::create($this->em);
        $first = $this->attempts->mutate($user, $course, 'start', []);
        $this->finish($user, $course, $this->em->find(QuizAttempt::class, $first['attemptId']));
        $this->attempts->mutate($user, $course, 'restart', ['attemptId' => $first['attemptId']]);
        $renewable = (new Quiz())->setTitle('Banque préexistante renouvelable')->setRenewalEnabled(true);
        $renewable->addQuestion(QuizFactory::question())->addQuestion(QuizFactory::question()->setReserve(true)->setPosition(1));
        $this->em->persist($renewable);
        $this->em->flush();
        $db->executeStatement('DROP TABLE quiz_manifest_identity');
        $beforeMigration = $this->rows();
        $tester = new CommandTester(new ModuleTwoQuizImportCommand($this->importer, $this->em, $this->destination));
        self::assertSame(1, $tester->execute($this->options()));
        $missing = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains('quiz_manifest_identity', $missing['schema_missing']);
        self::assertSame($beforeMigration, $this->rows());
        require_once dirname(__DIR__, 2).'/migrations/Version20261027120000.php';
        $migration = (new DbalMigrationFactory($db, new NullLogger()))->createVersion('DoctrineMigrations\\Version20261027120000');
        self::assertFalse($migration->isTransactional());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $sql) {
            $db->executeStatement($sql->getStatement());
        }
        self::assertSame([], (new SchemaTool($this->em))->getUpdateSchemaSql($this->em->getMetadataFactory()->getAllMetadata()));
        $before = $this->rows();
        self::assertSame(0, $tester->execute($this->options()));
        $plan = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($before, $this->rows());
        self::assertSame([3, 177, 354], [$plan['add_quizzes'], $plan['add_questions'], $plan['add_answers']]);
        self::assertSame(0, $tester->execute($this->options() + ['--apply' => true, '--expect-plan' => $plan['plan_hash']]));
        $after = $this->rows();
        foreach ($before as $table => $rows) {
            foreach ($rows as $row) {
                self::assertContains($row, $after[$table], $table);
            }
        }
        $imported = $this->importer->plan('import', $this->context);
        self::assertSame([], $imported['errors']);
        self::assertSame(177, $imported['existing_copies']);
        self::assertCount(3, $after['quiz_manifest_identity']);
        self::assertCount(177, $after['quiz_reserve_import']);
        foreach ($imported['quizzes'] as $quiz) {
            self::assertFalse($quiz['renewal_enabled']);
            self::assertNull($quiz['error_question_quota']);
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM courses WHERE quiz_id = ?', [$quiz['id']]));
        }
        $second = $this->apply();
        self::assertTrue($second['noop']);
        self::assertSame($after, $this->rows());
        $this->writeReport('import', ['destination' => $this->context, 'schema_preview' => $missing, 'preview' => $plan, 'second_import' => $second,
            'all_preexisting_rows_preserved' => true, 'after_import_sha256' => ModuleTwoQuizManifest::hash($after), 'after_second_import_sha256' => ModuleTwoQuizManifest::hash($this->rows()), 'database_cleanup' => 'tearDown drops the disposable database']);
    }

    public function testDifferentAutoIncrementsAndRollbackAfterInsert(): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement('ALTER TABLE quiz AUTO_INCREMENT = 7000');
        $db->executeStatement('ALTER TABLE quiz_question AUTO_INCREMENT = 12000');
        $db->executeStatement('ALTER TABLE quiz_answer AUTO_INCREMENT = 30000');
        $before = $this->rows();
        $listener = new class {
            public function postPersist(\Doctrine\ORM\Event\PostPersistEventArgs $event): void
            {
                if ($event->getObject() instanceof QuizReserveImport) {
                    throw new \RuntimeException('Échec injecté après insertion');
                }
            }
        };
        $this->em->getEventManager()->addEventListener(['postPersist'], $listener);
        try {
            $this->apply();
            self::fail('Rollback attendu');
        } catch (\RuntimeException $error) {
            self::assertSame('Échec injecté après insertion', $error->getMessage());
        }
        self::assertSame($before, $this->rows());
        $this->em->getEventManager()->removeEventListener(['postPersist'], $listener);
        // A failed Doctrine flush closes its manager. Use a fresh one for the retry.
        $this->em = $this->database->manager();
        $configuration = new QuizConfigurationValidator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
        $this->importer = new ModuleTwoQuizImporter($this->em, $this->manifest, $configuration, new QuizEditorialService($this->em, $configuration), $this->destination);
        $this->apply();
        $plan = $this->importer->plan('import', $this->context);
        self::assertGreaterThanOrEqual(7000, $plan['quizzes']['module2_test1']['id']);
        self::assertGreaterThanOrEqual(12000, min($plan['quizzes']['module2_test1']['question_ids']));
        $after = $this->rows();
        self::assertTrue($this->apply()['noop']);
        self::assertSame($after, $this->rows());
        $this->writeReport('offsets', ['destination' => $this->context, 'rollback_preserved_rows' => true, 'second_import_noop' => true, 'quiz_ids' => array_column($plan['quizzes'], 'id')]);
    }

    /** @return iterable<string, array{string}> */
    public static function corruptions(): iterable
    {
        foreach (['text', 'deleted', 'moved', 'answers', 'reserve', 'theme', 'position', 'trace_missing', 'trace_foreign', 'manifest_hash', 'identity_missing', 'quiz_deleted', 'untracked'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('corruptions')]
    public function testDivergenceRefusesEveryWrite(string $mode): void
    {
        $this->apply();
        $db = $this->em->getConnection();
        $trace = $db->fetchAssociative('SELECT * FROM quiz_reserve_import ORDER BY quiz_id, source_key LIMIT 1');
        $qid = $trace['question_id'];
        switch ($mode) {
            case 'text': case 'theme': $db->update('quiz_question', [$mode => 'Divergence'], ['id' => $qid]);
                break;
            case 'position': $db->update('quiz_question', ['position' => 999], ['id' => $qid]);
                break;
            case 'reserve': $db->executeStatement('UPDATE quiz_question SET reserve = 1 - reserve WHERE id = ?', [$qid]);
                break;
            case 'deleted': $db->delete('quiz_question', ['id' => $qid]);
                break;
            case 'moved': $db->update('quiz_question', ['quiz_id' => $db->fetchOne('SELECT MAX(id) FROM quiz')], ['id' => $qid]);
                break;
            case 'answers': $db->executeStatement('UPDATE quiz_answer SET correct = 1 - correct WHERE question_id = ?', [$qid]);
                break;
            case 'trace_missing': $db->delete('quiz_reserve_import', ['question_id' => $qid]);
                break;
            case 'trace_foreign': $db->update('quiz_reserve_import', ['source_key' => 'foreign'], ['question_id' => $qid]);
                break;
            case 'manifest_hash': $db->update('quiz_reserve_import', ['manifest_hash' => str_repeat('0', 64)], ['question_id' => $qid]);
                break;
            case 'identity_missing': $db->delete('quiz_manifest_identity', ['quiz_id' => $trace['quiz_id']]);
                break;
            case 'quiz_deleted': $db->delete('quiz', ['id' => $trace['quiz_id']]);
                break;
            case 'untracked': $q = QuizFactory::question()->setQuiz($this->em->find(Quiz::class, $trace['quiz_id']));
                $this->em->persist($q);
                $this->em->flush();
                break;
        }
        $before = $this->rows();
        $plan = $this->importer->plan('import', $this->context);
        self::assertNotEmpty($plan['differences']);
        try {
            $this->importer->apply('import', $plan['plan_hash'], $this->context);
            self::fail('Refus attendu');
        } catch (\DomainException $error) {
            self::assertStringContainsString('Divergences', $error->getMessage());
        }
        self::assertSame($before, $this->rows());
    }

    public function testCollisionStalePlanAndDestinationAssertions(): void
    {
        $plan = $this->importer->plan('import', $this->context);
        $collision = (new Quiz())->setTitle($this->manifest->data['quizzes'][0]['title']);
        $this->em->persist($collision);
        $this->em->flush();
        $before = $this->rows();
        $next = $this->importer->plan('import', $this->context);
        self::assertSame('title_collision', $next['differences'][0]['field']);
        try {
            $this->importer->apply('import', $plan['plan_hash'], $this->context);
            self::fail('Plan périmé attendu');
        } catch (\DomainException $error) {
            self::assertStringContainsString('périmé', $error->getMessage());
        }
        foreach (['database', 'host', 'port', 'environment', 'server'] as $field) {
            $changed = $this->context;
            $changed[$field] = 'port' === $field ? 3307 : 'different';
            try {
                $this->importer->apply('import', $next['plan_hash'], $changed);
                self::fail('Destination divergente');
            } catch (\DomainException) {
            }
        }
        self::assertSame($before, $this->rows());
        // Module 1 has not gained permission to write to module 2 recipe databases.
        $this->expectException(\DomainException::class);
        (new QuizCommandDestination('test'))->verify($this->em->getConnection(), ['apply' => true, 'expect-database' => $this->database->name]);
    }

    public function testChangedManifestBytesAndPreservedConfiguration(): void
    {
        $this->apply();
        $this->apply('enable');
        $db = $this->em->getConnection();
        $id = $db->fetchOne("SELECT quiz_id FROM quiz_manifest_identity WHERE quiz_key = 'module2_test1'");
        $db->update('quiz', ['error_question_quota' => 7], ['id' => $id]);
        $before = $this->rows();
        self::assertTrue($this->apply()['noop']);
        self::assertSame($before, $this->rows());
        $plan = $this->importer->plan('import', $this->context);
        self::assertSame(7, $plan['configuration_differences']['module2_test1']['error_question_quota']);
        $file = tempnam(sys_get_temp_dir(), 'module2-changed-');
        try {
            file_put_contents($file, file_get_contents(dirname(__DIR__, 2).'/resources/quiz/module2/module2_quiz_manifest.json')."\n");
            $changed = new ModuleTwoQuizManifest($file);
            $configuration = new QuizConfigurationValidator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
            $importer = new ModuleTwoQuizImporter($this->em, $changed, $configuration, new QuizEditorialService($this->em, $configuration), $this->destination);
            $plan = $importer->plan('import', $this->context);
            self::assertContains('manifest_hash', array_column($plan['differences'], 'field'));
            try {
                $importer->apply('import', $plan['plan_hash'], $this->context);
                self::fail('Manifeste différent doit être refusé');
            } catch (\DomainException) {
            }
            self::assertSame($before, $this->rows());
        } finally {
            unlink($file);
        }
        $this->apply('disable');
        self::assertSame(0, (int) $db->fetchOne('SELECT renewal_enabled FROM quiz WHERE id = ?', [$id]));
        self::assertSame(7, $db->fetchOne('SELECT error_question_quota FROM quiz WHERE id = ?', [$id]));
        $db->update('quiz', ['error_question_quota' => 999], ['id' => $id]);
        self::assertNotEmpty($this->importer->plan('enable', $this->context)['errors']);
    }

    public function testRealConcurrentCreationHasOneWinnerAndStaleLoser(): void
    {
        $db = $this->em->getConnection();
        $lock = 'quiz-m2-'.hash('sha1', $this->context['database']);
        $db->fetchOne('SELECT GET_LOCK(?, 10)', [$lock]);
        $workers = [];
        $files = [];
        try {
            for ($i = 0; $i < 2; ++$i) {
                $files[] = $ready = sys_get_temp_dir().'/module2-ready-'.bin2hex(random_bytes(8));
                $worker = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', dirname(__DIR__).'/Support/module2_import_worker.php', $this->database->name, $ready], dirname(__DIR__, 2), ['DATABASE_URL' => $this->database->kernelUrl()], timeout: 60);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 30;
            do {
                if (is_file($files[0]) && is_file($files[1])) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertFileExists($files[0]);
            self::assertFileExists($files[1]);
            $db->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
            $results = [];
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            self::assertCount(1, array_filter($results, static fn ($r) => $r['success']));
            self::assertCount(1, array_filter($results, static fn ($r) => !$r['success'] && str_contains($r['error'], 'périmé')));
            self::assertSame(3, (int) $db->fetchOne('SELECT COUNT(*) FROM quiz_manifest_identity'));
            self::assertSame(177, (int) $db->fetchOne('SELECT COUNT(*) FROM quiz_reserve_import'));
            self::assertSame([], $this->importer->plan('import', $this->context)['errors']);
            $this->writeReport('concurrency', ['destination' => $this->context, 'workers' => $results, 'identities' => 3, 'copies' => 177]);
        } finally {
            $db->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function testLearnerFixedRenewalAndImmutableHistoricalSnapshots(): void
    {
        [$user, $template] = QuizPlayerFactory::create($this->em);
        $this->apply();
        $plan = $this->importer->plan('import', $this->context);
        $simulation = json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/quiz/module2/module2_validation.json'), true, 512, JSON_THROW_ON_ERROR);
        $simulations = array_column($simulation['renewal_simulation'], null, 'quiz_key');
        $courses = $fixed = [];
        foreach ($this->manifest->data['quizzes'] as $spec) {
            $key = $spec['quiz_key'];
            $quiz = $this->em->find(Quiz::class, $plan['quizzes'][$key]['id']);
            $course = (new Courses())->setName($key)->setSlug($key)->setSection($template->getSection())->setContentType($template->getContentType())->setQuiz($quiz);
            $this->em->persist($course);
            $this->em->flush();
            $courses[$key] = $course;
            $state = $this->attempts->mutate($user, $course, 'start', []);
            $attempt = $this->em->find(QuizAttempt::class, $state['attemptId']);
            $ids = array_column($attempt->getSnapshot()['questions'], 'id');
            self::assertSame(array_map(fn ($k) => $plan['quizzes'][$key]['question_ids'][$k], $spec['initial_source_keys']), $ids);
            self::assertSame($spec['expected_initial_count'], $state['total']);
            self::assertEquals($state, $this->attempts->state($user, $course));
            $firstQuestion = $attempt->getSnapshot()['questions'][0];
            $interrupted = $this->attempts->mutate($user, $course, 'answer', ['attemptId' => $attempt->getId(), 'questionId' => $firstQuestion['id'], 'selectedIds' => array_column(array_filter($firstQuestion['answers'], static fn ($a) => $a['correct']), 'id')]);
            self::assertSame(1, $interrupted['validated']);
            self::assertEquals($interrupted, $this->attempts->state($user, $course));
            $this->finish($user, $course, $attempt);
            $retry = $this->attempts->mutate($user, $course, 'restart', ['attemptId' => $attempt->getId()]);
            $fixed[$key] = $this->em->find(QuizAttempt::class, $retry['attemptId']);
            self::assertSame($ids, array_column($fixed[$key]->getSnapshot()['questions'], 'id'));
        }
        $snapshots = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM quiz_attempt ORDER BY id');
        $enable = new CommandTester(new ModuleTwoQuizRenewalCommand($this->importer, $this->em, $this->destination));
        self::assertSame(0, $enable->execute($this->options() + ['mode' => 'enable']));
        $enablePlan = json_decode($enable->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $enable->execute($this->options() + ['mode' => 'enable', '--apply' => true, '--expect-plan' => $enablePlan['plan_hash']]));
        self::assertSame($snapshots, $this->em->getConnection()->fetchAllAssociative('SELECT * FROM quiz_attempt ORDER BY id'));
        $afterEnable = $this->rows();
        self::assertTrue($this->apply()['noop']);
        self::assertSame($afterEnable, $this->rows());
        foreach ($this->manifest->data['quizzes'] as $spec) {
            $key = $spec['quiz_key'];
            $course = $courses[$key];
            $previous = $fixed[$key];
            $history = $previous->getSnapshot();
            $this->finish($user, $course, $previous);
            $state = $this->attempts->mutate($user, $course, 'restart', ['attemptId' => $previous->getId()]);
            $next = $this->em->find(QuizAttempt::class, $state['attemptId']);
            $questions = $next->getSnapshot()['questions'];
            $keysById = array_flip($plan['quizzes'][$key]['question_ids']);
            self::assertSame($simulations[$key]['second_attempt'], array_map(static fn ($q) => $keysById[$q['id']], $questions));
            self::assertSame(array_column($history['questions'], 'theme'), array_column($questions, 'theme'));
            self::assertCount('module2_general' === $key ? 1 : 0, array_intersect(array_column($history['questions'], 'id'), array_column($questions, 'id')));
            $this->finish($user, $course, $next);
            $thirdState = $this->attempts->mutate($user, $course, 'restart', ['attemptId' => $next->getId()]);
            $third = $this->em->find(QuizAttempt::class, $thirdState['attemptId']);
            self::assertSame($spec['expected_initial_count'], $thirdState['total']);
            self::assertSame(array_column($history['questions'], 'theme'), array_column($third->getSnapshot()['questions'], 'theme'));
            $savedReview = $this->attempts->state($user, $course, $previous->getId());
            $question = $course->getQuiz()->getInitialQuestions()[0];
            $question->setExplanation('Correction éditoriale ultérieure en recette');
            $question->getAnswers()->first()->setContent('Libellé édité en recette');
            $this->em->flush();
            self::assertSame(ModuleTwoQuizManifest::hash($history), ModuleTwoQuizManifest::hash($previous->getSnapshot()));
            self::assertEquals($savedReview, $this->attempts->state($user, $course, $previous->getId()));
        }
        $this->writeReport('learner', ['destination' => $this->context, 'fixed_lengths' => [12, 12, 24], 'initial_and_second_attempts_match_documentary_simulator' => true,
            'resume_and_history_preserved' => true, 'minimum_reuse' => [0, 0, 1], 'activation_then_import_noop' => true]);
    }

    private function finish(\App\Entity\User $user, Courses $course, QuizAttempt $attempt): void
    {
        foreach ($attempt->getSnapshot()['questions'] as $question) {
            $state = $this->attempts->mutate($user, $course, 'answer', ['attemptId' => $attempt->getId(), 'questionId' => $question['id'], 'selectedIds' => array_column(array_filter($question['answers'], static fn ($a) => $a['correct']), 'id')]);
        }
        $state = $this->attempts->mutate($user, $course, 'finish', ['attemptId' => $attempt->getId()]);
        self::assertTrue($state['completed']);
        self::assertSame($attempt->getTotal(), $state['total']);
        self::assertSame($attempt->getTotal(), $state['score']);
        self::assertEquals(100, $state['percentage']);
    }

    /** @param array<string, mixed> $report */
    private function writeReport(string $name, array $report): void
    {
        if ('1' === getenv('MODULE2_RECIPE_REPORTS')) {
            file_put_contents(dirname(__DIR__).'/manual/module2_'.$name.'_report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }
    }
}
