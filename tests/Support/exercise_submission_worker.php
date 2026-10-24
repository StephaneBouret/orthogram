<?php

// A separate PHP process exercises real MySQL locks, never the development DB.
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
    throw new LogicException('Isolated test database required: '.$name);
}
$user = $em->find(App\Entity\User::class, (int) $argv[2]);
$course = $em->find(App\Entity\Courses::class, (int) $argv[3]);
$container->get('security.token_storage')->setToken(new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user, 'main', $user->getRoles()));
while (ob_get_level() > 0) {
    ob_end_flush();
}
echo "READY\n";
flush();
if (isset($argv[4])) {
    file_put_contents($argv[4], 'ready');
}
$result = $container->get(App\Services\ExerciceAttemptService::class)->submit($user, $course->getExercice(), ['yes'], $course);
echo json_encode(['completed' => $result['courseCompleted']], JSON_THROW_ON_ERROR);
$kernel->shutdown();
