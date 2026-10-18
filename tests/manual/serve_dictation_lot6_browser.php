<?php

// Both modes are explicit. The manual branch never invokes the CDP scenario.
if ('cli' !== PHP_SAPI || !in_array($argv[1] ?? '', ['--automated', '--manual'], true)) {
    exit("Usage: php tests/manual/serve_dictation_lot6_browser.php --automated|--manual\n");
}
if ('--manual' === $argv[1]) {
    require __DIR__.'/dictation_lot6_manual_session.php';
    exit;
}
require dirname(__DIR__).'/bootstrap.php';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['DICTATION_LEARNER_ENABLED'] = $_ENV['DICTATION_LEARNER_ENABLED'] = '1';
$database = new App\Tests\Support\DictationTestDatabase();
$server = null;
$stop = dirname(__DIR__, 2).'/var/dictation-6-browser.stop';
try {
    if (file_exists($stop)) {
        throw new LogicException('Ancien témoin présent : vérifier la recette précédente.');
    }
    $socket = @stream_socket_client('tcp://127.0.0.1:8815', $code, $message, 1);
    if (false !== $socket) {
        fclose($socket);
        throw new LogicException('Port 8815 occupé.');
    }
    $database->install();
    $em = $database->manager();
    [, $dictation, , $course] = App\Tests\Support\DictationFactory::create($em);
    $section = $course->getSection();
    $section->setSlug('section')->setName('Section de recette');
    $section->getProgram()->setSlug('lot6');
    $course->setSlug('cours')->setName('Cours requis avec un titre suffisamment long pour vérifier la navigation sur un petit écran')->setPartialFileName('lot6.html.twig');
    $foundation = (new App\Entity\Sections())->setName('Fondamentaux')->setSlug('base')->setProgram($section->getProgram())->setPosition(1);
    $free = (new App\Entity\Courses())->setName('Lecture libre')->setSlug('libre')->setSection($foundation)->setIsFree(true)->setPartialFileName('lot6.html.twig');
    $em->persist($foundation);
    $em->persist($free);
    $user = App\Tests\Support\QuizPlayerFactory::user('lot6-browser@example.test')->setRoles(['ROLE_USER']);
    $subscription = (new App\Entity\Subscription())->setEmail($user->getEmail())->setStatus(App\Enum\SubscriptionStatus::ACTIVE)->setIsLifetime(true);
    $user->addSubscription($subscription);
    $em->persist($user);
    $em->persist($subscription);
    $em->flush();
    if (!in_array($dictation->getId(), $user->getDictationPrerequisites() ?? [], true)) {
        throw new LogicException('Le compte neuf doit être inscrit par le listener réel.');
    }
    mkdir($_SERVER['COURSE_STORAGE_DIR'].'/files');
    file_put_contents($_SERVER['COURSE_STORAGE_DIR'].'/files/lot6.html.twig', '<p>Contenu de cours synthétique lot 6.</p>');
    $server = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-S', '127.0.0.1:8815', '-t', 'public', 'tests/manual/dictation_lot3_browser_router.php'], [
        0 => ['pipe', 'r'],
        1 => ['file', $_SERVER['DICTATION_STORAGE_DIR'].'/browser.stdout', 'a'],
        2 => ['file', $_SERVER['DICTATION_STORAGE_DIR'].'/browser.stderr', 'a'],
    ], $pipes, dirname(__DIR__, 2), array_merge(getenv(), [
        'APP_ENV' => 'test', 'APP_DEBUG' => '1', 'DICTATION_LEARNER_ENABLED' => '1', 'DATABASE_URL' => $database->kernelUrl(),
        'DICTATION_BROWSER_DB' => $database->name, 'DICTATION_BROWSER_USER' => (string) $user->getId(),
        'DICTATION_STORAGE_DIR' => $_SERVER['DICTATION_STORAGE_DIR'], 'COURSE_STORAGE_DIR' => $_SERVER['COURSE_STORAGE_DIR'],
    ]), ['bypass_shell' => true]);
    if (!is_resource($server)) {
        throw new RuntimeException('Impossible de démarrer le serveur isolé.');
    }
    fclose($pipes[0]);
    echo "READY http://127.0.0.1:8815/courses/lot6\n";
    $deadline = time() + 1200;
    while (!file_exists($stop) && time() < $deadline && proc_get_status($server)['running']) {
        usleep(100000);
    }
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $database->close();
    if (file_exists($stop)) {
        unlink($stop);
    }
    echo "CLEANED isolated MySQL database and test storage.\n";
}
