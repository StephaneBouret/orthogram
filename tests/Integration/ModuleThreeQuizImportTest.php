<?php

namespace App\Tests\Integration;

use App\Command\ModuleThreeQuizImportCommand;
use App\Command\ModuleThreeQuizRenewalCommand;
use App\Entity\Courses;
use App\Entity\Quiz;
use App\Entity\QuizAttempt;
use App\Entity\QuizReserveImport;
use App\Services\ModuleThreeQuizDestination;
use App\Services\ModuleThreeQuizImporter;
use App\Services\ModuleThreeQuizManifest;
use App\Services\QuizAttemptService;
use App\Services\QuizCommandDestination;
use App\Services\QuizConfigurationValidator;
use App\Services\QuizCorrectionService;
use App\Services\QuizEditorialService;
use App\Services\QuizQuestionSelector;
use App\Tests\Support\DictationTestDatabase;
use App\Tests\Support\QuizFactory;
use App\Tests\Support\QuizPlayerFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;
use Symfony\Component\Validator\Validation;

final class ModuleThreeQuizImportTest extends TestCase
{
    private DictationTestDatabase $database;
    private EntityManagerInterface $em;
    private ModuleThreeQuizManifest $manifest;
    private ModuleThreeQuizImporter $importer;
    private ModuleThreeQuizDestination $destination;
    private QuizAttemptService $attempts;
    /** @var array<string, mixed> */
    private array $context;

    protected function setUp(): void
    {
        $this->database = new DictationTestDatabase(recipe: 'module3');
        $this->database->install();
        $this->em = $this->database->manager();
        $this->manifest = new ModuleThreeQuizManifest(dirname(__DIR__, 2).'/resources/quiz/module3/module3_quiz_manifest.json');
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $configuration = new QuizConfigurationValidator($validator);
        $this->destination = new ModuleThreeQuizDestination(new QuizCommandDestination('test'));
        $this->importer = new ModuleThreeQuizImporter($this->em, $this->manifest, $configuration, new QuizEditorialService($this->em, $configuration), $this->destination);
        $this->attempts = new QuizAttemptService($this->em, new QuizCorrectionService($validator), new QuizQuestionSelector($configuration));
        $db = $this->em->getConnection();
        $this->context = $this->destination->verify($db, [
            'expect-env' => 'test', 'expect-database' => $this->database->name,
            'expect-host' => $db->getParams()['host'], 'expect-port' => (string) $db->getParams()['port'],
            'expect-server' => $db->fetchOne('SELECT @@hostname'),
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->clear();
        }
        if (isset($this->database)) {
            $this->database->close();
        }
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

    public function testLocalImportRequiresConsentAndRejectsRenewalAndProduction(): void
    {
        $destination = new ModuleThreeQuizDestination(new QuizCommandDestination('dev'));
        $configuration = new QuizConfigurationValidator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
        $importer = new ModuleThreeQuizImporter($this->em, $this->manifest, $configuration, new QuizEditorialService($this->em, $configuration), $destination);
        $import = new CommandTester(new ModuleThreeQuizImportCommand($importer, $this->em, $destination));
        $renewal = new CommandTester(new ModuleThreeQuizRenewalCommand($importer, $this->em, $destination));
        $local = array_replace($this->options(), ['--expect-env' => 'dev', '--expect-database' => 'orthogram']);
        $apply = ['--apply' => true, '--expect-plan' => str_repeat('a', 64)];
        $consent = ['--allow-work-database' => true];
        $before = $this->rows();

        // The local policy runs on the requested operation first. Every connection in this test is disposable.
        self::assertSame(1, $import->execute($local + $apply));
        self::assertStringContainsString('--allow-work-database requis', $import->getDisplay());
        foreach (['enable', 'disable'] as $mode) {
            foreach ([[], $apply] as $flags) {
                self::assertSame(1, $renewal->execute($local + ['mode' => $mode] + $flags + $consent));
                self::assertStringContainsString('Renouvellement interdit', $renewal->getDisplay());
            }
        }
        $context = array_replace($this->context, ['environment' => 'dev', 'database' => 'orthogram']);
        try {
            $importer->apply('import', $apply['--expect-plan'], $context);
            self::fail('Écriture locale acceptée par le service.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('--allow-work-database requis', $error->getMessage());
        }
        $pinned = array_replace($local, ['--expect-host' => '127.0.0.1', '--expect-port' => '3306', '--expect-server' => 'STEF-LEGION']);
        self::assertSame(1, $import->execute($pinned + $apply + $consent));
        self::assertStringContainsString('Connexion locale à la base attendue requise', $import->getDisplay());
        foreach (['--expect-host', '--expect-port', '--expect-server'] as $field) {
            self::assertSame(1, $import->execute(array_replace($pinned + $apply + $consent, [$field => 'different'])));
            self::assertStringContainsString('Destination différente de la base de travail validée', $import->getDisplay());
        }
        foreach ([[], $apply + $consent] as $flags) {
            self::assertSame(1, $import->execute(array_replace($local, ['--expect-env' => 'prod']) + $flags));
            self::assertStringContainsString('Base jetable locale du module 3 requise', $import->getDisplay());
        }
        self::assertSame(1, $import->execute($this->options() + ['--apply' => true]));
        // Exercise the CLI plan check on a permitted disposable destination.
        $testCommand = new CommandTester(new ModuleThreeQuizImportCommand($this->importer, $this->em, $this->destination));
        self::assertSame(1, $testCommand->execute($this->options() + ['--apply' => true]));
        self::assertStringContainsString('--expect-plan SHA-256 requis', $testCommand->getDisplay());
        self::assertSame(1, $testCommand->execute($this->options() + $apply));
        self::assertStringContainsString('Plan périmé sous verrou', $testCommand->getDisplay());
        // Import preview passes the operation check, then rejects the actual disposable database.
        self::assertSame(1, $import->execute($local));
        self::assertStringContainsString('Connexion locale à la base attendue requise', $import->getDisplay());
        self::assertSame($before, $this->rows());
    }

    public function testCommandImportAndActivationPreserveEveryPreexistingRow(): void
    {
        [$user, $course] = QuizPlayerFactory::create($this->em);
        $started = $this->attempts->mutate($user, $course, 'start', []);
        $this->finish($user, $course, $this->em->find(QuizAttempt::class, $started['attemptId']));
        $this->attempts->mutate($user, $course, 'restart', ['attemptId' => $started['attemptId']]);
        $before = $this->rows();
        $import = new CommandTester(new ModuleThreeQuizImportCommand($this->importer, $this->em, $this->destination));
        self::assertSame(0, $import->execute($this->options()), $import->getDisplay());
        $plan = json_decode($import->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($before, $this->rows());
        self::assertSame([3, 168, 336], [$plan['add_quizzes'], $plan['add_questions'], $plan['add_answers']]);
        foreach ($this->options() as $field => $value) {
            $options = $this->options();
            unset($options[$field]);
            self::assertSame(1, $import->execute($options));
            $options[$field] = '--expect-database' === $field ? 'orthogram' : 'wrong';
            self::assertSame(1, $import->execute($options + ['--apply' => true, '--expect-plan' => $plan['plan_hash']]));
        }
        self::assertSame($before, $this->rows());
        self::assertSame(0, $import->execute($this->options() + ['--apply' => true, '--expect-plan' => $plan['plan_hash']]), $import->getDisplay());
        $after = $this->rows();
        $changes = ['quiz' => 3, 'quiz_question' => 168, 'quiz_answer' => 336, 'quiz_manifest_identity' => 3, 'quiz_reserve_import' => 168];
        foreach ($before as $table => $rows) {
            self::assertCount(count($rows) + ($changes[$table] ?? 0), $after[$table], $table);
            foreach ($rows as $row) {
                self::assertContains($row, $after[$table], $table);
            }
        }
        $imported = $this->importer->plan('import', $this->context);
        self::assertSame([], $imported['errors']);
        self::assertSame([0, 0, 0, 168], [$imported['add_quizzes'], $imported['add_questions'], $imported['add_answers'], $imported['existing_copies']]);
        foreach ($imported['quizzes'] as $quiz) {
            self::assertFalse($quiz['renewal_enabled']);
            self::assertNull($quiz['error_question_quota']);
            self::assertNotContains($quiz['id'], array_column($after['courses'], 'quiz_id'));
        }
        $sources = array_count_values(array_column($after['quiz_reserve_import'], 'source_key'));
        self::assertCount(70, array_filter($sources, static fn ($n) => 2 === $n));
        self::assertCount(28, array_filter($sources, static fn ($n) => 1 === $n));
        self::assertCount(14, $imported['held_sources']);
        self::assertSame([], array_intersect(array_keys($sources), $imported['held_sources']));
        self::assertTrue($this->apply()['noop']);
        self::assertSame($after, $this->rows());

        $renewal = new CommandTester(new ModuleThreeQuizRenewalCommand($this->importer, $this->em, $this->destination));
        self::assertSame(0, $renewal->execute($this->options() + ['mode' => 'enable']));
        $activation = json_decode($renewal->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['renewal_enabled', 'renewal_enabled', 'renewal_enabled'], array_column($activation['renewal_changes'], 'field'));
        self::assertSame($after, $this->rows());
        self::assertSame(0, $renewal->execute($this->options() + ['mode' => 'enable', '--apply' => true, '--expect-plan' => $activation['plan_hash']]));
        $expected = $after;
        foreach ($expected['quiz'] as &$row) {
            if (in_array($row['id'], array_column($activation['renewal_changes'], 'quiz_id'), true)) {
                $row['renewal_enabled'] = 1;
            }
        }
        unset($row);
        self::assertSame($expected, $this->rows());
        $this->em->getConnection()->update('quiz', ['error_question_quota' => 5], ['id' => $imported['quizzes']['module3_test1']['id']]);
        $configured = $this->rows();
        self::assertTrue($this->apply()['noop']);
        self::assertSame($configured, $this->rows());
    }

    public function testRefusesCollisionsPartialImportTombstonesAndContentDrift(): void
    {
        $db = $this->em->getConnection();
        $source = $this->manifest->data['copies'][0]['source_key'];
        $candidate = $this->manifest->sources()[$source]['candidate'];
        $quiz = (new Quiz())->setTitle($this->manifest->data['quizzes'][0]['title']);
        $quiz->addQuestion(QuizFactory::question()->setText($candidate['text']));
        $this->em->persist($quiz);
        $this->em->flush();
        $this->assertBlocked(['title_collision', 'content_collision']);
        $db->delete('quiz', ['id' => $quiz->getId()]);
        $this->apply();
        $quizId = $db->fetchOne("SELECT quiz_id FROM quiz_manifest_identity WHERE quiz_key = 'module3_test1'");
        $qid = $db->fetchOne('SELECT question_id FROM quiz_reserve_import WHERE quiz_id = ? AND source_key = ?', [$quizId, $source]);
        $db->update('quiz_question', ['text' => 'Écart volontaire'], ['id' => $qid]);
        $plan = $this->assertBlocked(['text']);
        $difference = $plan['differences'][0];
        self::assertSame([$source, $candidate['text'], 'Écart volontaire'], [$difference['source_key'], $difference['expected'], $difference['actual']]);
        $db->update('quiz_reserve_import', ['payload_hash' => str_repeat('0', 64)], ['question_id' => $qid]);
        $db->executeStatement('UPDATE quiz_answer SET correct = 1 - correct WHERE question_id = ?', [$qid]);
        $this->assertBlocked(['payload_hash', 'answers']);
        $db->delete('quiz_question', ['id' => $qid]);
        $this->assertBlocked(['question_id']);
        $db->delete('quiz', ['id' => $quizId]);
        $this->assertBlocked(['quiz_id']);
        $db->delete('quiz_manifest_identity', ['quiz_key' => 'module3_general']);
        $partial = $this->assertBlocked(['orphan_traces']);
        self::assertStringContainsString('Import partiel', implode(' ', $partial['errors']));
    }

    /** @param list<string> $fields
     * @return array<string, mixed> */
    private function assertBlocked(array $fields): array
    {
        $before = $this->rows();
        $plan = $this->importer->plan('import', $this->context);
        foreach ($fields as $field) {
            self::assertContains($field, array_column($plan['differences'], 'field'));
        }
        try {
            $this->importer->apply('import', $plan['plan_hash'], $this->context);
            self::fail('Divergence acceptée');
        } catch (\DomainException $error) {
            self::assertStringContainsString('Divergences', $error->getMessage());
        }
        self::assertSame($before, $this->rows());

        return $plan;
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
        $this->importer = new ModuleThreeQuizImporter($this->em, $this->manifest, $configuration, new QuizEditorialService($this->em, $configuration), $this->destination);
        $this->apply();
        $plan = $this->importer->plan('import', $this->context);
        self::assertGreaterThanOrEqual(7000, $plan['quizzes']['module3_test1']['id']);
        self::assertGreaterThanOrEqual(12000, min($plan['quizzes']['module3_test1']['question_ids']));
        $after = $this->rows();
        self::assertTrue($this->apply()['noop']);
        self::assertSame($after, $this->rows());
    }

    public function testTwoConsoleImportsSerializeOneReviewedPlan(): void
    {
        $db = $this->em->getConnection();
        $plan = $this->importer->plan('import', $this->context);
        $lock = 'quiz-m3-'.hash('sha1', $this->database->name);
        self::assertSame(1, (int) $db->fetchOne('SELECT GET_LOCK(?, 5)', [$lock]));
        $args = [PHP_BINARY, '-d', 'xdebug.mode=off', 'bin/console', 'app:quiz:module3-import', '--env=test', '--apply', '--expect-plan='.$plan['plan_hash']];
        foreach ($this->options() as $key => $value) {
            $args[] = $key.'='.$value;
        }
        $workers = [];
        try {
            for ($i = 0; $i < 2; ++$i) {
                $worker = new Process($args, dirname(__DIR__, 2), ['DATABASE_URL' => $this->database->kernelUrl(), 'APP_ENV' => 'test', 'SYMFONY_DOTENV_VARS' => false]);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 12;
            do {
                $waiting = (int) $db->fetchOne("SELECT COUNT(*) FROM information_schema.processlist WHERE db = ? AND state = 'User lock'", [$this->database->name]);
                if (2 === $waiting) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline && $workers[0]->isRunning() && $workers[1]->isRunning());
            self::assertSame(2, $waiting, implode(' ', array_map(static fn ($w) => $w->getOutput().$w->getErrorOutput(), $workers)));
            $db->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
            $results = [];
            foreach ($workers as $worker) {
                $results[$worker->wait()] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            self::assertSame(168, $results[0]['questions_added']);
            self::assertStringContainsString('périmé', $results[1]['error']);
            self::assertSame([], $this->importer->plan('import', $this->context)['errors']);
        } finally {
            $db->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
    }

    public function testLearnerFixedRenewalAndImmutableHistoricalSnapshots(): void
    {
        [$user, $template] = QuizPlayerFactory::create($this->em);
        $this->apply();
        $plan = $this->importer->plan('import', $this->context);
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
            self::assertSame([], $state['review']);
            self::assertArrayNotHasKey('explanation', $state['question']);
            foreach ($state['question']['answers'] as $answer) {
                self::assertSame(['id', 'content'], array_keys($answer));
            }
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
        $enable = new CommandTester(new ModuleThreeQuizRenewalCommand($this->importer, $this->em, $this->destination));
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
            self::assertSame(array_column($history['questions'], 'theme'), array_column($questions, 'theme'));
            self::assertCount(0, array_intersect(array_column($history['questions'], 'id'), array_column($questions, 'id')));
            // First unused reserves in editorial order fill the initial theme slots.
            $matrix = array_column($spec['theme_matrix'], 'reserve', 'theme');
            $expectedIds = [];
            foreach ($history['questions'] as $slot) {
                $source = sprintf('module3_docx_%03d', array_shift($matrix[$slot['theme']]));
                $expectedIds[] = $plan['quizzes'][$key]['question_ids'][$source];
            }
            self::assertSame($expectedIds, array_column($questions, 'id'));
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
            self::assertSame(ModuleThreeQuizManifest::hash($history), ModuleThreeQuizManifest::hash($previous->getSnapshot()));
            self::assertEquals($savedReview, $this->attempts->state($user, $course, $previous->getId()));
        }
    }

    private function finish(\App\Entity\User $user, Courses $course, QuizAttempt $attempt): void
    {
        foreach ($attempt->getSnapshot()['questions'] as $question) {
            $this->attempts->mutate($user, $course, 'answer', ['attemptId' => $attempt->getId(), 'questionId' => $question['id'], 'selectedIds' => array_column(array_filter($question['answers'], static fn ($a) => $a['correct']), 'id')]);
        }
        $state = $this->attempts->mutate($user, $course, 'finish', ['attemptId' => $attempt->getId()]);
        self::assertTrue($state['completed']);
        self::assertSame($attempt->getTotal(), $state['total']);
        self::assertSame($attempt->getTotal(), $state['score']);
        self::assertEquals(100, $state['percentage']);
    }
}
