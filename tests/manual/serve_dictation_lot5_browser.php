<?php

declare(strict_types=1);

// Explicitly selected automated/manual mode; exclusively disposable fixtures.
if ('cli' !== PHP_SAPI || !in_array($argv[1] ?? '', ['--automated', '--manual', '--regression'], true)) {
    exit("Usage: php tests/manual/serve_dictation_lot5_browser.php --automated|--manual|--regression\n");
}
require dirname(__DIR__).'/bootstrap.php';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$database = new App\Tests\Support\DictationTestDatabase();
$server = null;
$sessionSuffix = '--manual' === $argv[1] ? '-'.bin2hex(random_bytes(6)) : '';
$stop = dirname(__DIR__, 2).'/var/dictation-5-browser'.$sessionSuffix.'.stop';
try {
    if (file_exists($stop)) {
        throw new LogicException('Ancien témoin présent : vérifier la recette précédente.');
    }
    $socket = @stream_socket_client('tcp://127.0.0.1:8815', $code, $message, 1);
    if (false !== $socket) {
        fclose($socket);
        throw new LogicException('Port 8815 occupé : ne pas arrêter une session existante.');
    }
    $database->install();
    $em = $database->manager();
    [$user, , $source, $course] = App\Tests\Support\DictationFactory::create($em);
    $subscription = (new App\Entity\Subscription())->setEmail($user->getEmail())->setStatus(App\Enum\SubscriptionStatus::ACTIVE)->setIsLifetime(true);
    $user->addSubscription($subscription);
    $em->persist($subscription);
    $em->flush();
    $editorial = new App\Services\Dictation\DictationEditorialService($em, new App\Services\Dictation\DictationCorrector(), App\Tests\Support\DictationAudioFixture::storage());
    $workflow = App\Tests\Support\DictationFactory::service($em);
    for ($i = 1; $i <= 7; ++$i) {
        if ($i > 1) {
            [, , $source, $course] = App\Tests\Support\DictationFactory::create($em);
        }
        $draft = $editorial->cloneVersion($source, $source->getRevision());
        $draft->edit('Recette lot 5 — '.$i, 'L’école ouvre.', 5 === $i ? null : "Explications synthétiques.\n<b>Texte inerte</b>");
        $draft->editSegments([['start' => 0, 'end' => 600], ['start' => 600, 'end' => 1200], ['start' => 1200, 'end' => 1800]]);
        $editorial->saveDraft($draft, $draft->getRevision());
        $editorial->publish($draft, $draft->getRevision());
        if ('--regression' === $argv[1]) {
            continue; // Empty journeys for the unchanged lot 3/4 browser suites.
        }
        $state = $workflow->start($user, $source->getDictation()->getId());
        $state = $workflow->transition($user, $state['attemptId'], App\Enum\DictationAction::Evaluate, 0, 4 === $i ? 'L’école ouvre.' : 'L’ ouvre.');
        if ('self_correction' === $state['state']) {
            $workflow->transition($user, $state['attemptId'], App\Enum\DictationAction::Close, 1, match ($i) {
                5 => 'L’école ouvre.',
                6 => 'L’école ouvre',
                default => "L’ecole ouvre.\nCopie clôturée.",
            });
        }
        // Actual Lesson rows: no client flag, no direct attempt-state mutation.
        $em->persist((new App\Entity\Lesson())->setName('Cours validé de recette')->setUser($user)->setCourse($course)->setStatus(App\Enum\LessonStatus::DONE));
        $em->flush();
    }
    // Direct process handle: no cmd.exe intermediary left alive on Windows teardown.
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
    $deadline = time() + 1200;
    echo "READY http://127.0.0.1:8815/dictees/1 (seven isolated journeys)\n";
    echo 'Isolated database: '.$database->name.PHP_EOL;
    echo 'Isolated dictation storage: '.$_SERVER['DICTATION_STORAGE_DIR'].PHP_EOL;
    echo 'Stop: create '.$stop.PHP_EOL;
    echo 'Expires (Europe/Paris): '.(new DateTimeImmutable('@'.$deadline))->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d H:i:s T').PHP_EOL;
    while (!file_exists($stop) && microtime(true) < $deadline && proc_get_status($server)['running']) {
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
