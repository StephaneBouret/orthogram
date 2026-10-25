<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$workerUrl = getenv('DATABASE_URL');
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $workerUrl;
$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get(Doctrine\ORM\EntityManagerInterface::class);
$connection = $em->getConnection();
$name = $connection->fetchOne('SELECT DATABASE()');
if ($name !== $argv[1] || 1 !== preg_match('/^orthogram_dictation_2a_[a-f0-9]{16}_test$/D', $name)) {
    throw new LogicException('Base isolée requise.');
}
$question = $em->find(App\Entity\QuizQuestion::class, (int) $argv[2]);
$target = (int) $argv[3] > 0 ? $em->find(App\Entity\Quiz::class, (int) $argv[3]) : null;
// Intentionally stale, initialized graph before either writer acquires its lock.
foreach ($question->getQuiz()->getQuestions() as $existing) {
    $existing->getAnswers()->toArray();
}
$target?->getQuestions()->toArray();
file_put_contents($argv[4], (string) $connection->fetchOne('SELECT CONNECTION_ID()'));
$service = $container->get(App\Services\QuizEditorialService::class);
try {
    $service->transaction($question, $target?->getId(), function () use ($question, $target, $service, $em): void {
        $source = $question->getQuiz();
        $question->setQuiz($target);
        $service->validate($question, $source);
        if (null === $target) {
            $em->remove($question);
        }
        $em->flush();
    });
    echo json_encode(['status' => 200]);
} catch (App\Exception\InvalidQuizConfiguration $error) {
    echo json_encode(['status' => 409, 'error' => $error->getMessage()]);
}
$kernel->shutdown();
