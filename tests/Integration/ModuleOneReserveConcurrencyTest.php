<?php

namespace App\Tests\Integration;

use App\Entity\QuizAttempt;
use App\Services\ModuleOneReserveImporter;
use App\Services\ModuleOneReserveManifest;
use App\Services\QuizAttemptService;
use App\Services\QuizConfigurationValidator;
use App\Services\QuizCorrectionService;
use App\Services\QuizEditorialService;
use App\Services\QuizQuestionSelector;
use App\Tests\Support\DictationTestDatabase;
use App\Tests\Support\ModuleOneReserveFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Validator\Validation;

final class ModuleOneReserveConcurrencyTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function races(): iterable
    {
        yield 'deux imports' => ['import', 'import'];
        yield 'import et édition' => ['import', 'edit'];
        yield 'import et premier démarrage' => ['import', 'start'];
        yield 'import et redémarrage fixe' => ['import', 'restart'];
        yield 'activation et redémarrage' => ['enable', 'restart'];
    }

    #[DataProvider('races')]
    public function testSharedLocksSerializeImportsAndSnapshots(string $firstAction, string $secondAction): void
    {
        $database = new DictationTestDatabase();
        $workers = $files = [];
        try {
            $database->install();
            $em = $database->manager();
            $db = $em->getConnection();
            $manifest = new ModuleOneReserveManifest(dirname(__DIR__, 2).'/resources/quiz/module1_reserve.json');
            $fixture = ModuleOneReserveFixture::create($em, $manifest);
            $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
            $configuration = new QuizConfigurationValidator($validator);
            $importer = new ModuleOneReserveImporter($em, new QuizEditorialService($em, $configuration), $configuration, $manifest);
            $attempts = new QuizAttemptService($em, new QuizCorrectionService($validator), new QuizQuestionSelector($configuration));
            $user = $fixture['user'];
            $course = $fixture['courses']['module1_test1'];
            $quiz = $course->getQuiz();
            $initialIds = array_map(static fn ($q) => $q->getId(), $quiz->getInitialQuestions());
            $previousId = 0;
            if ('restart' === $secondAction) {
                $started = $attempts->mutate($user, $course, 'start', []);
                $previousId = $started['attemptId'];
                $previous = $em->find(QuizAttempt::class, $previousId);
                foreach ($previous->getSnapshot()['questions'] as $q) {
                    $attempts->mutate($user, $course, 'answer', ['attemptId' => $previousId, 'questionId' => $q['id'], 'selectedIds' => array_column(array_filter($q['answers'], static fn ($a) => $a['correct']), 'id')]);
                }
                $attempts->mutate($user, $course, 'finish', ['attemptId' => $previousId]);
            }
            if ('enable' === $firstAction) {
                $plan = $importer->plan();
                $importer->apply('import', $plan['plan_hash']);
            }
            $oldAttempts = $db->fetchAllAssociative('SELECT * FROM quiz_attempt ORDER BY id');
            $db->beginTransaction();
            $db->executeQuery('SELECT id FROM quiz WHERE id = ? FOR UPDATE', [$quiz->getId()])->fetchOne();
            foreach ([$firstAction, $secondAction] as $action) {
                $files[] = $ready = sys_get_temp_dir().'/reserve-race-'.bin2hex(random_bytes(12));
                $worker = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'xdebug.mode=off', dirname(__DIR__).'/Support/module1_reserve_worker.php',
                    $database->name, $action, (string) $user->getId(), (string) $course->getId(), $ready, (string) $previousId], dirname(__DIR__, 2),
                    ['DATABASE_URL' => $database->kernelUrl(), 'APP_ENV' => 'test'], timeout: 45);
                $worker->start();
                $workers[] = $worker;
            }
            foreach ($workers as $i => $worker) {
                $deadline = microtime(true) + 20;
                while (!is_file($files[$i]) && microtime(true) < $deadline) {
                    usleep(20000);
                }
                self::assertFileExists($files[$i], $worker->getErrorOutput());
            }
            usleep(200000);
            self::assertNotSame(file_get_contents($files[0]), file_get_contents($files[1]));
            foreach ($workers as $worker) {
                self::assertTrue($worker->isRunning());
                self::assertSame('', $worker->getOutput());
            }
            $db->commit();
            $results = [];
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            self::assertTrue($results[1]['success']);
            if ('edit' === $secondAction) {
                self::assertSame('Édition concurrente conservée.', $db->fetchOne('SELECT explanation FROM quiz_question WHERE id = ?', [$initialIds[0]]));
                self::assertSame($results[0]['success'] ? 94 : 0, (int) $db->fetchOne('SELECT COUNT(*) FROM quiz_reserve_import'));
                if (!$results[0]['success']) {
                    self::assertStringContainsString('prévisualisation', $results[0]['error']);
                }
            } else {
                self::assertTrue($results[0]['success'], json_encode($results[0]));
                self::assertSame(94, (int) $db->fetchOne('SELECT COUNT(*) FROM quiz_reserve_import'));
                self::assertSame(208, (int) $db->fetchOne('SELECT COUNT(*) FROM quiz_question'));
            }
            if ('import' === $secondAction) {
                $added = [$results[0]['result']['questions_added'], $results[1]['result']['questions_added']];
                sort($added);
                self::assertSame([0, 94], $added);
            }
            foreach ($oldAttempts as $row) {
                self::assertSame($row, $db->fetchAssociative('SELECT * FROM quiz_attempt WHERE id = ?', [$row['id']]));
            }
            if (in_array($secondAction, ['start', 'restart'], true)) {
                $row = $db->fetchAssociative('SELECT * FROM quiz_attempt WHERE id = ?', [$results[1]['result']['attemptId']]);
                $snapshot = json_decode($row['snapshot'], true, 512, JSON_THROW_ON_ERROR);
                $selected = array_column($snapshot['questions'], 'id');
                self::assertCount(14, $selected);
                if ('import' === $firstAction) {
                    self::assertSame($initialIds, $selected);
                } else {
                    self::assertContains(count(array_intersect($initialIds, $selected)), [2, 14]);
                }
                self::assertSame($previousId ? 2 : 1, (int) $db->fetchOne('SELECT COUNT(*) FROM quiz_attempt'));
            }
        } finally {
            if (isset($db) && $db->isTransactionActive()) {
                $db->rollBack();
            }
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
            $database->close();
        }
    }
}
