<?php

declare(strict_types=1);

use App\Entity\DictationAttempt;
use App\Entity\Lesson;
use App\Entity\User;
use App\Enum\DictationAction;
use App\Enum\LessonStatus;
use App\Tests\Support\DictationFactory;
use App\Tests\Support\DictationTestDatabase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$worker = 'worker' === ($argv[1] ?? '');
$database = new DictationTestDatabase($worker ? ($argv[2] ?? '') : null);
$processes = [];
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
try {
    if ($worker) {
        $em = $database->manager();
        $user = $em->find(User::class, (int) $argv[3]) ?? throw new LogicException('Utilisateur de test absent.');
        $payload = json_decode(base64_decode($argv[5], true), true, 512, JSON_THROW_ON_ERROR);
        if (isset($payload['attemptId'])) {
            $em->find(DictationAttempt::class, $payload['attemptId']);
        }
        echo 'READY '.$em->getConnection()->fetchOne('SELECT CONNECTION_ID()')."\n";
        flush();
        $service = DictationFactory::service($em);
        try {
            $state = 'start' === $argv[4] ? $service->start($user, $payload['dictationId'])
                : $service->transition($user, $payload['attemptId'], DictationAction::from($argv[4]), $payload['revision'], $payload['copy'] ?? null);
            $result = ['status' => 200, 'state' => $state];
        } catch (HttpExceptionInterface $exception) {
            $result = ['status' => $exception->getStatusCode()];
        }
        echo 'RESULT '.json_encode($result, JSON_THROW_ON_ERROR)."\n";
    } else {
        echo 'Base isolée : '.$database->name."\n";
        $database->install();
        $em = $database->manager();
        $connection = $em->getConnection();
        [$user, $dictation, , $course] = DictationFactory::create($em);
        $userId = $user->getId();
        /** @param array<string, mixed> $first
         * @param array<string, mixed>|null $second
         *
         * @return list<array<string, mixed>>
         */
        $race = static function (string $action, array $first, ?string $otherAction = null, ?array $second = null) use ($database, $userId, $connection, $check, &$processes): array {
            $connection->beginTransaction();
            $connection->executeQuery('SELECT id FROM user WHERE id = ? FOR UPDATE', [$userId])->fetchOne();
            $processes = [];
            foreach ([[$action, $first], [$otherAction ?? $action, $second ?? $first]] as [$operation, $payload]) {
                $process = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', __FILE__, 'worker', $database->name, (string) $userId, $operation, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))]);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }
            $ids = [];
            foreach ($processes as $process) {
                // Include output buffered while starting the second worker (not only new chunks).
                $deadline = microtime(true) + 10;
                while (!str_contains($process->getOutput(), 'READY ') && $process->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $check($process->isRunning(), 'Worker arrêté avant acquisition du verrou : '.$process->getErrorOutput());
                preg_match('/READY (\d+)/', $process->getOutput(), $matches);
                $ids[] = $matches[1] ?? throw new RuntimeException('Worker non prêt.');
            }
            $check($ids[0] !== $ids[1], 'Deux connexions SQL distinctes requises.');
            $connection->commit();
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $check($process->isSuccessful(), 'Échec worker : '.$process->getErrorOutput());
                preg_match('/RESULT (.+)/', $process->getOutput(), $matches);
                $results[] = json_decode($matches[1] ?? throw new RuntimeException('Résultat absent.'), true, 512, JSON_THROW_ON_ERROR);
            }
            echo 'OK écritures concurrentes '.$action.'/'.($otherAction ?? $action).' : '.implode(',', array_column($results, 'status'))."\n";

            return $results;
        };
        $results = $race('start', ['dictationId' => $dictation->getId()]);
        $check(200 === $results[0]['status'] && $results[0] === $results[1], 'Double création non idempotente.');
        $id = $results[0]['state']['attemptId'];
        $check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM dictation_attempt'), 'Une seule tentative attendue.');
        $results = $race('evaluate', ['attemptId' => $id, 'revision' => 0, 'copy' => 'L’ ouvre.']);
        $check(200 === $results[0]['status'] && $results[0] === $results[1], 'Première évaluation recalculée ou différente.');
        $firstFeedback = $results[0]['state']['initialFeedback'];
        $results = $race('save', ['attemptId' => $id, 'revision' => 1, 'copy' => 'L’ecole ouvre.'], 'save', ['attemptId' => $id, 'revision' => 1, 'copy' => 'L’ ouvre.']);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $check([200, 409] === $statuses, 'Une seule sauvegarde concurrente doit gagner.');
        $results = $race('close', ['attemptId' => $id, 'revision' => 2, 'copy' => 'L’ecole ouvre.']);
        $check(200 === $results[0]['status'] && $results[0] === $results[1], 'Double clôture non idempotente.');
        $lesson = (new Lesson())->setUser($user)->setCourse($course)->setName('Terminé')->setStatus(LessonStatus::DONE);
        $em->persist($lesson);
        $em->flush();
        $results = $race('resume', ['attemptId' => $id, 'revision' => 3]);
        $check(200 === $results[0]['status'] && $results[0] === $results[1], 'Double reprise non idempotente.');
        $results = $race('finalize', ['attemptId' => $id, 'revision' => 4, 'copy' => 'L’école ouvre.'], 'save', ['attemptId' => $id, 'revision' => 4, 'copy' => 'sauvegarde concurrente']);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $check([200, 409] === $statuses, 'La sauvegarde et la finalisation ne doivent pas être acceptées à la même révision.');
        $revision = (int) $connection->fetchOne('SELECT revision FROM dictation_attempt WHERE id = ?', [$id]);
        $state = $connection->fetchOne('SELECT state FROM dictation_attempt WHERE id = ?', [$id]);
        $finalRevision = 'completed' === $state ? 4 : $revision;
        $results = $race('finalize', ['attemptId' => $id, 'revision' => $finalRevision, 'copy' => 'L’école ouvre.']);
        $check(200 === $results[0]['status'] && $results[0] === $results[1], 'Double finalisation non idempotente.');
        $results = $race('save', ['attemptId' => $id, 'revision' => 4, 'copy' => 'sauvegarde concurrente']);
        $check([409, 409] === array_column($results, 'status'), 'Sauvegarde tardive acceptée.');
        $check('L’école ouvre.' === $connection->fetchOne('SELECT final_copy FROM dictation_attempt WHERE id = ?', [$id]), 'Copie finalisée écrasée.');
        $em->clear();
        $attempt = $em->find(DictationAttempt::class, $id);
        $check($firstFeedback == $attempt->getInitialResult(), 'Premier signalement altéré.');
        echo "OK unicité, première évaluation, révisions, clôture, reprise, finalisation et sauvegardes tardives.\n";
    }
} finally {
    if (isset($connection) && $connection->isTransactionActive()) {
        $connection->rollBack();
    }
    foreach ($processes as $process) {
        if ($process->isRunning()) {
            $process->stop();
        }
    }
    $database->close();
}
