<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$url = getenv('DATABASE_URL');
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $url;
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get(Doctrine\ORM\EntityManagerInterface::class);
$db = $em->getConnection();
$name = $db->fetchOne('SELECT DATABASE()');
if ($name !== $argv[1] || 1 !== preg_match('/^orthogram_dictation_2a_[a-f0-9]{16}_test$/D', $name)) {
    throw new LogicException('Base jetable requise.');
}
$action = $argv[2];
$user = $em->find(App\Entity\User::class, (int) $argv[3]);
$course = $em->find(App\Entity\Courses::class, (int) $argv[4]);
$importer = $container->get(App\Services\ModuleOneReserveImporter::class);
$editorial = $container->get(App\Services\QuizEditorialService::class);
$attempts = $container->get(App\Services\QuizAttemptService::class);
$plan = in_array($action, ['import', 'enable'], true) ? $importer->plan($action) : null;
// Warm the identity map to test refresh under the shared quiz lock.
foreach ($course->getQuiz()->getQuestions() as $question) {
    $question->getAnswers()->toArray();
}
file_put_contents($argv[5], (string) $db->fetchOne('SELECT CONNECTION_ID()'));
try {
    if (in_array($action, ['import', 'enable'], true)) {
        $result = $importer->apply($action, $plan['plan_hash']);
    } elseif ('edit' === $action) {
        $question = $course->getQuiz()->getInitialQuestions()[0];
        $editorial->transaction($question, null, function () use ($question, $editorial, $em): void {
            $question->setExplanation('Édition concurrente conservée.');
            $editorial->validate($question, $question->getQuiz());
            $em->flush();
        });
        $result = ['edited' => true];
    } else {
        $result = $attempts->mutate($user, $course, $action, 'restart' === $action ? ['attemptId' => (int) $argv[6]] : []);
    }
    echo json_encode(['success' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (DomainException $error) {
    echo json_encode(['success' => false, 'error' => $error->getMessage()], JSON_THROW_ON_ERROR);
}
$kernel->shutdown();
