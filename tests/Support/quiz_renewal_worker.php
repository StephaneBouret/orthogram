<?php

// Independent MySQL writers; only the random, guarded test database is accepted.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$workerUrl = getenv('DATABASE_URL');
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $workerUrl;
$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get(Doctrine\ORM\EntityManagerInterface::class);
$name = $em->getConnection()->fetchOne('SELECT DATABASE()');
if ($name !== $argv[1] || 1 !== preg_match('/^orthogram_dictation_2a_[a-f0-9]{16}_test$/D', $name)) {
    throw new LogicException('Base de test isolée requise.');
}
$user = $em->find(App\Entity\User::class, (int) $argv[2]);
$course = $em->find(App\Entity\Courses::class, (int) $argv[3]);
$payload = json_decode($argv[5], true, 512, JSON_THROW_ON_ERROR);
if (isset($payload['attemptId'])) {
    $em->find(App\Entity\QuizAttempt::class, $payload['attemptId']);
}
file_put_contents($argv[6], (string) $em->getConnection()->fetchOne('SELECT CONNECTION_ID()'));
$state = $container->get(App\Services\QuizAttemptService::class)->mutate($user, $course, $argv[4], $payload);
$snapshot = $em->find(App\Entity\QuizAttempt::class, $state['attemptId'])->getSnapshot();
echo json_encode(['state' => $state, 'snapshot' => $snapshot], JSON_THROW_ON_ERROR);
$kernel->shutdown();
