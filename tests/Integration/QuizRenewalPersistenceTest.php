<?php

namespace App\Tests\Integration;

use App\Entity\Courses;
use App\Entity\Quiz;
use App\Entity\QuizAttempt;
use App\Entity\User;
use App\Services\QuizAttemptService;
use App\Services\QuizConfigurationValidator;
use App\Services\QuizCorrectionService;
use App\Services\QuizQuestionSelector;
use App\Tests\Support\DictationTestDatabase;
use App\Tests\Support\QuizFactory;
use App\Tests\Support\QuizPlayerFactory;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Version\DbalMigrationFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;
use Symfony\Component\Validator\Validation;

final class QuizRenewalPersistenceTest extends TestCase
{
    private function service(EntityManagerInterface $em): QuizAttemptService
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        return new QuizAttemptService($em, new QuizCorrectionService($validator), new QuizQuestionSelector(new QuizConfigurationValidator($validator)));
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function finish(QuizAttemptService $service, User $user, Courses $course, array $state): array
    {
        while (null !== $state['question']) {
            $question = $state['question'];
            $state = $service->mutate($user, $course, 'answer', ['attemptId' => $state['attemptId'], 'questionId' => $question['id'],
                'selectedIds' => array_column(array_slice($question['answers'], 0, $question['multiple'] ? 2 : 1), 'id')]);
        }

        return $service->mutate($user, $course, 'finish', ['attemptId' => $state['attemptId']]);
    }

    public function testAdditiveMigrationKeepsOldStartedAndCompletedAttemptsAndImportDefaults(): void
    {
        // Reuse the repository's guarded MySQL helper (its historical prefix says dictation).
        $database = new DictationTestDatabase();
        try {
            $database->install();
            $em = $database->manager();
            [$user, $course] = QuizPlayerFactory::create($em);
            $service = $this->service($em);
            $completed = $this->finish($service, $user, $course, $service->mutate($user, $course, 'start', []));
            $started = $service->mutate($user, $course, 'restart', ['attemptId' => $completed['attemptId']]);
            $started = $service->mutate($user, $course, 'answer', ['attemptId' => $started['attemptId'], 'questionId' => $started['question']['id'],
                'selectedIds' => [$started['question']['answers'][0]['id']]]);
            $userId = $user->getId();
            $courseId = $course->getId();
            $quizId = $course->getQuiz()->getId();
            $connection = $em->getConnection();
            self::assertSame($database->name, $connection->getDatabase());
            // Reconstruct the previous schema, containing v1 JSON and real progress rows.
            $connection->executeStatement('ALTER TABLE quiz DROP renewal_enabled, DROP error_question_quota');
            $connection->executeStatement('ALTER TABLE quiz_question DROP reserve, DROP contains_error');
            $before = [];
            foreach (['quiz', 'quiz_question', 'quiz_answer', 'quiz_attempt', 'courses', 'lesson', 'user'] as $table) {
                $before[$table] = $connection->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
            }
            require_once dirname(__DIR__, 2).'/migrations/Version20261025120000.php';
            $migration = (new DbalMigrationFactory($connection, new NullLogger()))->createVersion('DoctrineMigrations\\Version20261025120000');
            $migration->up(new Schema());
            foreach ($migration->getSql() as $sql) {
                $connection->executeStatement($sql->getStatement());
            }
            foreach ($before as $table => $rows) {
                $after = $connection->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
                foreach ($after as &$row) {
                    if ('quiz' === $table) {
                        self::assertSame(0, (int) $row['renewal_enabled']);
                        self::assertNull($row['error_question_quota']);
                        unset($row['renewal_enabled'], $row['error_question_quota']);
                    }
                    if ('quiz_question' === $table) {
                        self::assertSame(0, (int) $row['reserve']);
                        self::assertNull($row['contains_error']);
                        unset($row['reserve'], $row['contains_error']);
                    }
                }
                unset($row);
                self::assertSame($rows, $after, $table.' modified by migration');
            }
            self::assertSame([], (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata()));
            $em->clear();
            $user = $em->find(User::class, $userId);
            $course = $em->find(Courses::class, $courseId);
            $quiz = $em->find(Quiz::class, $quizId);
            self::assertFalse($quiz->isRenewalEnabled());
            // Object order of MySQL JSON is normalized, so compare decoded values.
            self::assertEquals($completed, $service->state($user, $course, $completed['attemptId']));
            self::assertEquals($started, $service->state($user, $course, $started['attemptId']));
            $initial = array_map(static fn ($q) => $q->getId(), $quiz->getInitialQuestions());
            self::assertSame($initial, array_column($em->find(QuizAttempt::class, $started['attemptId'])->getSnapshot()['questions'], 'id'));
            $quiz->setRenewalEnabled(true);
            $reserve = QuizFactory::question()->setQuiz($quiz)->setReserve(true);
            $em->flush();
            self::assertEquals($started, $service->mutate($user, $course, 'restart', ['attemptId' => $completed['attemptId']]));
            $this->finish($service, $user, $course, $started);
            $next = $service->mutate($user, $course, 'restart', ['attemptId' => $started['attemptId']]);
            self::assertSame($reserve->getId(), $next['question']['id']);
            self::assertSame(2, $next['total']);
            self::assertEquals($completed, $service->state($user, $course, $completed['attemptId']));
            // Legacy SQL imports omit the new fields completely.
            $connection->insert('quiz', ['title' => 'Import ancien']);
            $importId = (int) $connection->lastInsertId();
            $connection->insert('quiz_question', ['quiz_id' => $importId, 'title' => 'Initiale importée', 'explanation' => '', 'multiple' => 0, 'position' => 0]);
            self::assertSame(0, (int) $connection->fetchOne('SELECT renewal_enabled FROM quiz WHERE id = ?', [$importId]));
            self::assertSame(0, (int) $connection->fetchOne('SELECT reserve FROM quiz_question WHERE quiz_id = ?', [$importId]));
        } finally {
            $database->close();
        }
    }

    public function testConcurrentStartsAndRenewedRestartsPersistOneSnapshot(): void
    {
        $database = new DictationTestDatabase();
        try {
            $database->install();
            $em = $database->manager();
            [$user, $course] = QuizPlayerFactory::create($em);
            $quiz = $course->getQuiz()->setRenewalEnabled(true);
            $initial = array_map(static fn ($q) => $q->getId(), $quiz->getInitialQuestions());
            $reserve = QuizFactory::question()->setQuiz($quiz)->setReserve(true);
            $em->flush();
            $started = $this->race($database, $em, $user, $course, 'start', []);
            self::assertSame($initial, array_column($started['snapshot']['questions'], 'id'));
            $completed = $this->finish($this->service($em), $user, $course, $started['state']);
            $next = $this->race($database, $em, $user, $course, 'restart', ['attemptId' => $completed['attemptId']]);
            self::assertSame($reserve->getId(), $next['state']['question']['id']);
            self::assertSame(2, $next['state']['total']);
            self::assertSame(2, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM quiz_attempt'));
            self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM quiz_attempt WHERE active_slot = 1'));
            self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM quiz_attempt WHERE previous_attempt_id = ?', [$completed['attemptId']]));
            self::assertEquals($next['state'], $this->service($em)->mutate($user, $course, 'restart', ['attemptId' => $completed['attemptId']]));
            self::assertEquals($next['snapshot'], $em->find(QuizAttempt::class, $next['state']['attemptId'])->getSnapshot());
        } finally {
            $database->close();
        }
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function race(DictationTestDatabase $database, EntityManagerInterface $em, User $user, Courses $course, string $action, array $payload): array
    {
        $workers = $readyFiles = [];
        $connection = $em->getConnection();
        try {
            $connection->beginTransaction();
            $connection->executeQuery('SELECT id FROM user WHERE id = ? FOR UPDATE', [$user->getId()])->fetchOne();
            for ($i = 0; $i < 2; ++$i) {
                $readyFiles[] = $ready = sys_get_temp_dir().'/quiz-worker-'.bin2hex(random_bytes(12));
                $worker = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'memory_limit=512M', dirname(__DIR__).'/Support/quiz_renewal_worker.php',
                    $database->name, (string) $user->getId(), (string) $course->getId(), $action, json_encode($payload, JSON_THROW_ON_ERROR), $ready],
                    dirname(__DIR__, 2), ['DATABASE_URL' => $database->kernelUrl(), 'APP_ENV' => 'test'], timeout: 30);
                $worker->start();
                $workers[] = $worker;
            }
            foreach ($workers as $index => $worker) {
                $deadline = microtime(true) + 15;
                while (!is_file($readyFiles[$index]) && microtime(true) < $deadline) {
                    usleep(20000);
                }
                self::assertFileExists($readyFiles[$index], $worker->getErrorOutput());
            }
            usleep(200000);
            self::assertNotSame(file_get_contents($readyFiles[0]), file_get_contents($readyFiles[1]), 'Two independent MySQL connections');
            foreach ($workers as $worker) {
                self::assertTrue($worker->isRunning(), $worker->getErrorOutput());
                self::assertSame('', $worker->getOutput(), 'Both writes must wait for the user lock');
            }
            $connection->commit();
            $results = [];
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            self::assertEquals($results[0], $results[1], 'Same persistent attempt and full snapshot');

            return $results[0];
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach ($readyFiles as $ready) {
                if (is_file($ready)) {
                    unlink($ready);
                }
            }
        }
    }
}
