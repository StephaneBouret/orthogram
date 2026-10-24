<?php

namespace App\Tests\Integration;

use App\Entity\Exercice;
use App\Enum\CourseContentType;
use App\Tests\Support\DictationTestDatabase;
use App\Tests\Support\QuizPlayerFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ExerciseCompletionPersistenceTest extends TestCase
{
    public function testConcurrentFirstSuccessCreatesOneLessonAndFailureRollsBackAttempt(): void
    {
        $database = new DictationTestDatabase();
        $workers = [];
        $readyFiles = [];
        try {
            $database->install();
            $em = $database->manager();
            [$user, $course] = QuizPlayerFactory::create($em);
            $exercise = (new Exercice())->setTitle('Concurrence')->setInstruction('Choisir')->setSentences([
                ['words' => [['id' => 'yes', 'text' => 'Chante', 'isAnswer' => true]]],
            ]);
            $course->setContentType(CourseContentType::Exercise)->setExercice($exercise);
            $em->persist($exercise);
            $em->flush();
            $connection = $em->getConnection();
            $connection->beginTransaction();
            $connection->executeQuery('SELECT id FROM user WHERE id = ? FOR UPDATE', [$user->getId()]);
            for ($i = 0; $i < 2; ++$i) {
                $ready = sys_get_temp_dir().'/exercise-worker-'.bin2hex(random_bytes(12));
                $readyFiles[] = $ready;
                $worker = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', dirname(__DIR__).'/Support/exercise_submission_worker.php',
                    $database->name, (string) $user->getId(), (string) $course->getId(), $ready], dirname(__DIR__, 2),
                    ['DATABASE_URL' => $database->kernelUrl(), 'APP_ENV' => 'test'], timeout: 30);
                $worker->start();
                $workers[] = $worker;
            }
            foreach ($workers as $index => $worker) {
                $deadline = microtime(true) + 15;
                while (!is_file($readyFiles[$index]) && microtime(true) < $deadline) {
                    usleep(20000);
                }
                self::assertFileExists($readyFiles[$index]);
                self::assertTrue($worker->isRunning(), 'Submission must wait for the user lock.');
            }
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM exercice_attempt'));
            $connection->commit();
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
                self::assertStringContainsString('"completed":true', $worker->getOutput());
            }
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM lesson'));
            self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM exercice_attempt'));

            // Force a persistence failure after the attempt insert, on this disposable DB only.
            $connection->executeStatement('DELETE FROM lesson');
            $connection->executeStatement("CREATE TRIGGER fail_exercise_lesson BEFORE INSERT ON lesson FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic lesson failure'");
            $worker = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', dirname(__DIR__).'/Support/exercise_submission_worker.php',
                $database->name, (string) $user->getId(), (string) $course->getId()], dirname(__DIR__, 2),
                ['DATABASE_URL' => $database->kernelUrl(), 'APP_ENV' => 'test'], timeout: 30);
            $workers[] = $worker;
            self::assertNotSame(0, $worker->run());
            self::assertStringNotContainsString('"completed":true', $worker->getOutput());
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM lesson'));
            self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM exercice_attempt'));
        } finally {
            if (isset($connection) && $connection->isTransactionActive()) {
                $connection->rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            $database->close();
            foreach ($readyFiles as $ready) {
                if (is_file($ready)) {
                    unlink($ready);
                }
            }
        }
    }
}
