<?php

declare(strict_types=1);

// Disposable local database only. The lot 1 migration is never loaded or executed.
use App\Entity\Courses;
use App\Entity\QuizAttempt;
use App\Entity\User;
use App\Kernel;
use App\Services\QuizAttemptService;
use App\Services\QuizCorrectionService;
use App\Tests\Support\QuizPlayerFactory;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Version\DbalMigrationFactory;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use Psr\Log\NullLogger;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__, 2).'/migrations/Version20260913160000.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel('test', true);
$kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
$params = $registry->getConnection()->getParams();
if (!in_array($params['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true) || 'pdo_mysql' !== ($params['driver'] ?? '')) {
    throw new LogicException('MySQL local requis.');
}
unset($params['dbname'], $params['url']);
$worker = 'worker' === ($argv[1] ?? '');
$database = $worker ? ($argv[2] ?? '') : 'orthogram_quiz_lot2_'.bin2hex(random_bytes(8)).'_test';
if (1 !== preg_match('/^orthogram_quiz_lot2_[a-f0-9]{16}_test$/D', $database)) {
    throw new LogicException('Nom de base jetable invalide.');
}
$server = DriverManager::getConnection($params);
$created = false;
$processes = [];
$connection = null;
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
try {
    if (!$worker) {
        $server->executeStatement('CREATE DATABASE '.$server->quoteIdentifier($database).' CHARACTER SET utf8mb4');
        $created = true;
    }
    $params['dbname'] = $database;
    $connection = DriverManager::getConnection($params);
    $check($connection->getDatabase() === $database, 'Connexion non isolée.');
    $baseEm = $registry->getManager();
    if (!$baseEm instanceof Doctrine\ORM\EntityManagerInterface) {
        throw new LogicException('Doctrine ORM requis.');
    }
    $em = new EntityManager($connection, $baseEm->getConfiguration(), $baseEm->getEventManager());
    $validator = $kernel->getContainer()->get('test.service_container')->get('validator');
    $service = new QuizAttemptService($em, new QuizCorrectionService($validator), $validator);
    if ($worker) {
        $user = $em->find(User::class, (int) $argv[3]);
        $course = $em->find(Courses::class, (int) $argv[4]);
        $payload = json_decode(base64_decode($argv[6], true), true, 512, JSON_THROW_ON_ERROR);
        // Deliberately preload a stale attempt before waiting on the shared user lock.
        if (isset($payload['attemptId'])) {
            $em->find(QuizAttempt::class, $payload['attemptId']);
        }
        echo 'READY '.$connection->fetchOne('SELECT CONNECTION_ID()')."\n";
        flush();
        try {
            $result = ['status' => 200, 'state' => $service->mutate($user, $course, $argv[5], $payload)];
        } catch (HttpExceptionInterface $error) {
            $result = ['status' => $error->getStatusCode()];
        }
        echo 'RESULT '.json_encode($result, JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    $check([] === (new SchemaValidator($em))->validateMapping(), 'Mapping invalide.');
    (new SchemaTool($em))->createSchema(array_values(array_filter($metadata, static fn ($m) => QuizAttempt::class !== $m->name)));
    $factory = new DbalMigrationFactory($connection, new NullLogger());
    $migration = $factory->createVersion('DoctrineMigrations\\Version20260913160000');
    $migration->up(new Schema());
    foreach ($migration->getSql() as $sql) {
        $connection->executeStatement($sql->getStatement());
    }
    $changes = (new SchemaTool($em))->getUpdateSchemaSql($metadata);
    $check([] === $changes, 'Le SQL de migration diffère du mapping : '.implode('; ', $changes));
    [$user, $course] = QuizPlayerFactory::create($em);
    $userId = $user->getId();
    $courseId = $course->getId();

    // The parent holds the row lock until BOTH children are ready and blocked.
    // No PHP session is shared: these are actual simultaneous database writers.
    $race = function (string $action, array $first, ?array $second = null) use (&$processes, $connection, $database, $userId, $courseId, $check): array {
        $connection->beginTransaction();
        $connection->executeQuery('SELECT id FROM user WHERE id = ? FOR UPDATE', [$userId])->fetchOne();
        $processes = [];
        foreach ([$first, $second ?? $first] as $payload) {
            $process = new Process([PHP_BINARY, __FILE__, 'worker', $database, (string) $userId, (string) $courseId, $action, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))], dirname(__DIR__, 2));
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 20;
        do {
            $ready = count(array_filter($processes, static fn (Process $p) => str_contains($p->getOutput(), 'READY ')));
            if ($ready < 2) {
                usleep(20000);
            }
        } while ($ready < 2 && microtime(true) < $deadline);
        $check(2 === $ready, 'Les deux processus ne sont pas prêts : '.implode(' ', array_map(static fn ($p) => $p->getErrorOutput(), $processes)));
        usleep(200000);
        $ids = [];
        foreach ($processes as $process) {
            $check($process->isRunning() && !str_contains($process->getOutput(), 'RESULT '), 'Le verrou doit bloquer les deux écritures.');
            preg_match('/READY (\d+)/', $process->getOutput(), $matches);
            $ids[] = $matches[1];
        }
        $check($ids[0] !== $ids[1], 'Deux connexions distinctes requises.');
        $connection->commit();
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $check($process->isSuccessful(), 'Échec worker : '.$process->getErrorOutput());
            preg_match('/RESULT (.+)/', $process->getOutput(), $matches);
            $results[] = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        }
        echo 'OK concurrence '.$action.' (connexions '.implode(', ', $ids).")\n";

        return $results;
    };
    $results = $race('start', []);
    $check(200 === $results[0]['status'] && $results[0]['state']['attemptId'] === $results[1]['state']['attemptId'], 'Double démarrage.');
    $state = $results[0]['state'];
    $payload = ['attemptId' => $state['attemptId'], 'questionId' => $state['question']['id'], 'selectedIds' => [$state['question']['answers'][0]['id']]];
    $results = $race('answer', $payload);
    // MySQL JSON normalizes object key order; compare JSON objects by value.
    $check($results[0] == $results[1] && 1 === $results[0]['state']['score'], 'Rejeu de réponse non idempotent.');
    $state = $results[0]['state'];
    $first = ['attemptId' => $state['attemptId'], 'questionId' => $state['question']['id'], 'selectedIds' => [$state['question']['answers'][0]['id'], $state['question']['answers'][1]['id']]];
    $second = $first;
    $second['selectedIds'] = [$state['question']['answers'][2]['id']];
    $results = $race('answer', $first, $second);
    $statuses = array_column($results, 'status');
    sort($statuses);
    $check([200, 409] === $statuses, 'Deux sélections concurrentes doivent accepter une seule réponse.');
    $results = $race('finish', ['attemptId' => $state['attemptId']]);
    $check($results[0] == $results[1] && $results[0]['state']['completed'], 'Double finalisation.');
    $check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM lesson WHERE status = ?', ['done']), 'Une seule leçon DONE attendue.');
    $results = $race('restart', ['attemptId' => $state['attemptId']]);
    $check($results[0] == $results[1] && $results[0]['state']['attemptId'] !== $state['attemptId'], 'Double redémarrage.');
    $check(2 === (int) $connection->fetchOne('SELECT COUNT(*) FROM quiz_attempt'), 'Historique de deux tentatives attendu.');
    $check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM quiz_attempt WHERE active_slot = 1'), 'Un seul slot actif attendu.');
    // FK history: editorial deletions and course deletion preserve the attempt JSON.
    $before = $connection->fetchAllAssociative('SELECT snapshot, responses FROM quiz_attempt ORDER BY id');
    $connection->executeStatement('DELETE FROM quiz');
    $connection->delete('courses', ['id' => $courseId]);
    $check($before === $connection->fetchAllAssociative('SELECT snapshot, responses FROM quiz_attempt ORDER BY id'), 'Historique altéré par une suppression.');
    $check(2 === (int) $connection->fetchOne('SELECT COUNT(*) FROM quiz_attempt WHERE course_id IS NULL AND quiz_id IS NULL'), 'FK SET NULL attendues.');
    $migration = $factory->createVersion('DoctrineMigrations\\Version20260913160000');
    $migration->down(new Schema());
    foreach ($migration->getSql() as $sql) {
        $connection->executeStatement($sql->getStatement());
    }
    $check(!in_array('quiz_attempt', $connection->createSchemaManager()->listTableNames(), true), 'Down incomplet.');
    echo "OK migration lot 2 up/down, mapping, historique et écritures concurrentes.\n";
} finally {
    if ($connection?->isTransactionActive()) {
        $connection->rollBack();
    }
    foreach ($processes as $process) {
        if ($process->isRunning()) {
            $process->stop();
        }
    }
    $connection?->close();
    if ($created) {
        $server->executeStatement('DROP DATABASE '.$server->quoteIdentifier($database));
        echo "Base isolée supprimée.\n";
    }
    $server->close();
    $kernel->shutdown();
}
