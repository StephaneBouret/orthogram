<?php

namespace App\Tests\Integration;

use App\Command\QuizRenewalCommand;
use App\Command\QuizReserveCommand;
use App\Services\ModuleOneReserveImporter;
use App\Services\ModuleOneReserveManifest;
use App\Services\QuizCommandDestination;
use App\Services\QuizConfigurationValidator;
use App\Services\QuizEditorialService;
use App\Tests\Support\DictationTestDatabase;
use App\Tests\Support\ModuleOneReserveFixture;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\Validation;

final class QuizProductionCommandTest extends TestCase
{
    private DictationTestDatabase $database;
    private EntityManagerInterface $em;
    private ModuleOneReserveImporter $importer;
    /** @var array<string, mixed> */
    private array $options;

    protected function setUp(): void
    {
        $this->database = new DictationTestDatabase();
        $this->database->install();
        $this->em = $this->database->manager();
        $manifest = new ModuleOneReserveManifest(dirname(__DIR__, 2).'/resources/quiz/module1_reserve.json');
        ModuleOneReserveFixture::create($this->em, $manifest);
        $validator = new QuizConfigurationValidator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
        $this->importer = new ModuleOneReserveImporter($this->em, new QuizEditorialService($this->em, $validator), $validator, $manifest);
        $db = $this->em->getConnection();
        $this->options = ['--expect-env' => 'prod', '--expect-database' => $this->database->name, '--expect-host' => $db->getParams()['host'],
            '--expect-port' => (string) ($db->getParams()['port'] ?? 3306), '--expect-server' => $db->fetchOne('SELECT @@hostname')];
    }

    protected function tearDown(): void
    {
        $this->database->close();
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed> */
    private function runCommand(array $options, int $expected = 0, string $environment = 'prod', ?string $mode = null): array
    {
        $guard = new QuizCommandDestination($environment);
        $command = null === $mode ? new QuizReserveCommand($this->importer, $this->em, $guard) : new QuizRenewalCommand($this->importer, $this->em, $guard);
        $tester = new CommandTester($command);
        if (null !== $mode) {
            $options['mode'] = $mode;
        }
        self::assertSame($expected, $tester->execute($options), $tester->getDisplay());

        return json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function rows(): array
    {
        $db = $this->em->getConnection();
        $result = [];
        foreach (['quiz', 'quiz_question', 'quiz_answer', 'quiz_reserve_import', 'user', 'courses', 'quiz_attempt', 'lesson'] as $table) {
            $result[$table] = $db->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY '.('quiz_reserve_import' === $table ? 'quiz_id, source_key' : 'id'));
        }

        return $result;
    }

    public function testProductionRefusalsNeverWrite(): void
    {
        $before = $this->rows();
        $plan = $this->runCommand($this->options);
        $write = $this->options + ['--apply' => true, '--allow-production-database' => true, '--expect-plan' => $plan['plan_hash']];
        foreach ([
            ['--expect-env' => null], ['--expect-env' => 'dev'], ['--expect-database' => 'another_database'],
            ['--expect-host' => null], ['--expect-host' => 'wrong.invalid'], ['--expect-port' => '3307'], ['--expect-port' => null],
            ['--expect-server' => 'another_server'], ['--expect-server' => null], ['--allow-production-database' => false],
            ['--allow-work-database' => true], ['--expect-plan' => str_repeat('0', 64)], ['--expect-plan' => str_repeat('x', 64)],
        ] as $changes) {
            $options = array_filter(array_replace($write, $changes), static fn ($value) => null !== $value);
            $report = $this->runCommand($options, 1);
            self::assertFalse($report['applied']);
            self::assertSame($before, $this->rows());
        }
        $this->runCommand($write, 1, 'dev');
        $this->runCommand($this->options, 1, 'staging');
        self::assertSame($before, $this->rows());
    }

    public function testProductionPreviewImportRepeatAndSeparateActivation(): void
    {
        $before = $this->rows();
        $plan = $this->runCommand($this->options);
        self::assertSame($before, $this->rows());
        self::assertSame('prod', $plan['destination']['environment']);
        self::assertCount(4, $plan['targets']);
        $result = $this->runCommand($this->options + ['--apply' => true, '--allow-production-database' => true, '--expect-plan' => $plan['plan_hash']]);
        self::assertSame(94, $result['questions_added']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM quiz WHERE renewal_enabled = 1'));
        $imported = $this->rows();
        // Production checks the exact fresh plan even for an otherwise identical reimport.
        $this->runCommand($this->options + ['--apply' => true, '--allow-production-database' => true, '--expect-plan' => $plan['plan_hash']], 1);
        $repeat = $this->runCommand($this->options);
        self::assertSame(0, $repeat['add_questions']);
        $this->runCommand($this->options + ['--apply' => true, '--allow-production-database' => true, '--expect-plan' => $repeat['plan_hash']]);
        self::assertSame($imported, $this->rows());
        $enable = $this->runCommand($this->options, mode: 'enable');
        self::assertSame($imported, $this->rows());
        $this->runCommand($this->options + ['--apply' => true, '--allow-production-database' => true, '--expect-plan' => $enable['plan_hash']], mode: 'enable');
        self::assertSame(4, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM quiz WHERE renewal_enabled = 1'));
        $enabled = $this->rows();
        unset($enabled['quiz'], $imported['quiz']);
        self::assertSame($imported, $enabled);
        $disable = $this->runCommand($this->options, mode: 'disable');
        $this->runCommand($this->options + ['--apply' => true, '--allow-production-database' => true, '--expect-plan' => $disable['plan_hash']], mode: 'disable');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM quiz WHERE renewal_enabled = 1'));
        self::assertSame(94, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM quiz_reserve_import'));
    }

    public function testLocalPlansCannotAuthorizeProductionAndChangedInitialsAreNeverOverwritten(): void
    {
        $local = $this->runCommand(['--expect-database' => $this->database->name], environment: 'test');
        $prod = $this->runCommand($this->options);
        self::assertNotSame($local['plan_hash'], $prod['plan_hash']);
        $this->runCommand($this->options + ['--apply' => true, '--allow-production-database' => true, '--expect-plan' => $local['plan_hash']], 1);
        $id = $prod['quizzes']['module1_test1']['initial_ids'][0];
        $this->em->getConnection()->update('quiz_question', ['explanation' => 'Production : contenu à préserver'], ['id' => $id]);
        $before = $this->rows();
        $this->runCommand($this->options + ['--apply' => true, '--allow-production-database' => true, '--expect-plan' => $prod['plan_hash']], 1);
        $fresh = $this->runCommand($this->options);
        self::assertNotEmpty($fresh['initial_differences']);
        $this->runCommand($this->options + ['--apply' => true, '--allow-production-database' => true, '--expect-plan' => $fresh['plan_hash']], 1);
        self::assertSame($before, $this->rows());
    }

    public function testExistingLocalCopyWorkflowStillWorks(): void
    {
        $options = ['--expect-database' => $this->database->name];
        $before = $this->rows();
        $plan = $this->runCommand($options, environment: 'test');
        self::assertSame($before, $this->rows());
        $result = $this->runCommand($options + ['--apply' => true, '--expect-plan' => $plan['plan_hash']], environment: 'test');
        self::assertSame(94, $result['questions_added']);
    }
}
