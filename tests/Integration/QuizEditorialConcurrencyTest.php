<?php

namespace App\Tests\Integration;

use App\Entity\Quiz;
use App\Services\QuizAttemptService;
use App\Services\QuizConfigurationValidator;
use App\Services\QuizCorrectionService;
use App\Services\QuizQuestionSelector;
use App\Tests\Support\DictationTestDatabase;
use App\Tests\Support\QuizPlayerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Validator\Validation;

final class QuizEditorialConcurrencyTest extends TestCase
{
    /** @return iterable<string, array{bool, bool}> */
    public static function changes(): iterable
    {
        yield 'suppressions simultanées avec renouvellement' => [false, true];
        yield 'déplacements simultanés avec renouvellement' => [true, true];
        yield 'suppressions simultanées en mode fixe avec quota' => [false, false];
        yield 'déplacements simultanés en mode fixe avec quota' => [true, false];
    }

    #[DataProvider('changes')]
    public function testConcurrentEditsCannotEmptyAConstrainedQuizAndKeepExistingSnapshot(bool $move, bool $renewal): void
    {
        $database = new DictationTestDatabase();
        $workers = $readyFiles = [];
        try {
            $database->install();
            $em = $database->manager();
            [$user, $course] = QuizPlayerFactory::create($em);
            $quiz = $course->getQuiz()->setRenewalEnabled($renewal);
            if (!$renewal) {
                $quiz->setErrorQuestionQuota(0);
                foreach ($quiz->getQuestions() as $question) {
                    $question->setContainsError(false)->setTheme(null);
                }
            }
            $target = (new Quiz())->setTitle('Destination fixe');
            $em->persist($target);
            $em->flush();
            $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
            $attempts = new QuizAttemptService($em, new QuizCorrectionService($validator), new QuizQuestionSelector(new QuizConfigurationValidator($validator)));
            $started = $attempts->mutate($user, $course, 'start', []);
            $connection = $em->getConnection();
            $before = $connection->fetchAllAssociative('SELECT * FROM quiz_attempt ORDER BY id');
            $connection->beginTransaction();
            $connection->executeQuery('SELECT id FROM quiz WHERE id = ? FOR UPDATE', [$quiz->getId()])->fetchOne();
            foreach ($quiz->getQuestions() as $question) {
                $readyFiles[] = $ready = sys_get_temp_dir().'/quiz-editorial-'.bin2hex(random_bytes(12));
                $worker = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'memory_limit=512M', dirname(__DIR__).'/Support/quiz_editorial_worker.php',
                    $database->name, (string) $question->getId(), $move ? (string) $target->getId() : '0', $ready], dirname(__DIR__, 2),
                    ['DATABASE_URL' => $database->kernelUrl(), 'APP_ENV' => 'test'], timeout: 30);
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
            self::assertNotSame(file_get_contents($readyFiles[0]), file_get_contents($readyFiles[1]));
            foreach ($workers as $worker) {
                self::assertTrue($worker->isRunning(), $worker->getErrorOutput());
                self::assertSame('', $worker->getOutput(), 'Both editors must wait for the quiz lock.');
            }
            $connection->commit();
            $statuses = [];
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $statuses[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status'];
            }
            sort($statuses);
            self::assertSame([200, 409], $statuses);
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM quiz_question WHERE quiz_id = ?', [$quiz->getId()]));
            self::assertSame($move ? 1 : 0, (int) $connection->fetchOne('SELECT COUNT(*) FROM quiz_question WHERE quiz_id = ?', [$target->getId()]));
            self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM quiz_attempt ORDER BY id'));
            self::assertEquals($started, $attempts->state($user, $course, $started['attemptId']));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM lesson'));
        } finally {
            if (isset($connection) && $connection->isTransactionActive()) {
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
            $database->close();
        }
    }
}
