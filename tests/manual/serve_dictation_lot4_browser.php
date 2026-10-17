<?php

declare(strict_types=1);

// Disposable MySQL + private storage. CLI only, loopback server, automatic teardown.
if ('cli' !== PHP_SAPI) {
    exit(1);
}
require dirname(__DIR__).'/bootstrap.php';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$database = new App\Tests\Support\DictationTestDatabase();
$server = null;
$stop = dirname(__DIR__, 2).'/var/dictation-4-browser.stop';
if (file_exists($stop)) {
    $database->close();
    throw new LogicException('Ancien témoin présent : vérifier la recette précédente.');
}
try {
    $database->install();
    $em = $database->manager();
    [$user, , $source] = App\Tests\Support\DictationFactory::create($em);
    $subscription = (new App\Entity\Subscription())->setEmail($user->getEmail())->setStatus(App\Enum\SubscriptionStatus::ACTIVE)->setIsLifetime(true);
    $user->addSubscription($subscription);
    $em->persist($subscription);
    $em->flush();
    $editorial = new App\Services\Dictation\DictationEditorialService($em, new App\Services\Dictation\DictationCorrector(), App\Tests\Support\DictationAudioFixture::storage());
    $draft = $editorial->cloneVersion($source, $source->getRevision());
    $draft->editSegments([['start' => 0, 'end' => 600], ['start' => 600, 'end' => 1200], ['start' => 1200, 'end' => 1800]]);
    $editorial->saveDraft($draft, $draft->getRevision());
    $editorial->publish($draft, $draft->getRevision());
    // Additional independent journeys; the same subscribed test user can access these sections.
    for ($i = 0; $i < 6; ++$i) {
        [, , $extra] = App\Tests\Support\DictationFactory::create($em);
        $next = $editorial->cloneVersion($extra, $extra->getRevision());
        $next->editSegments([['start' => 0, 'end' => 600], ['start' => 600, 'end' => 1200], ['start' => 1200, 'end' => 1800]]);
        $editorial->saveDraft($next, $next->getRevision());
        $editorial->publish($next, $next->getRevision());
    }
    // Optional private editorial input for a dedicated eighth test journey.
    // Never read a development entity or ship real editorial text with the tests.
    if (isset($argv[1])) {
        $input = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($input) || !isset($input['reference']) || !is_string($input['reference'])) {
            throw new InvalidArgumentException('Référence de recette requise.');
        }
        [, , $extra] = App\Tests\Support\DictationFactory::create($em);
        $next = $editorial->cloneVersion($extra, $extra->getRevision());
        $next->edit('Recette de navigation isolée', $input['reference'], null, []);
        $editorial->saveDraft($next, $next->getRevision());
        $editorial->publish($next, $next->getRevision());
    }
    $server = new Symfony\Component\Process\Process([PHP_BINARY, '-d', 'xdebug.mode=off', '-S', '127.0.0.1:8815', '-t', 'public', 'tests/manual/dictation_lot3_browser_router.php'], dirname(__DIR__, 2), [
        'APP_ENV' => 'test', 'APP_DEBUG' => '1', 'DICTATION_LEARNER_ENABLED' => '1', 'DATABASE_URL' => $database->kernelUrl(),
        'DICTATION_BROWSER_DB' => $database->name, 'DICTATION_BROWSER_USER' => (string) $user->getId(),
        'DICTATION_STORAGE_DIR' => $_SERVER['DICTATION_STORAGE_DIR'], 'COURSE_STORAGE_DIR' => $_SERVER['COURSE_STORAGE_DIR'],
    ]);
    $server->setTimeout(null);
    $server->start();
    echo 'READY http://127.0.0.1:8815/dictees/'.$source->getDictation()->getId().PHP_EOL;
    echo 'Stop: create var/dictation-4-browser.stop (automatic timeout: 30 minutes).'.PHP_EOL;
    $deadline = microtime(true) + 1800;
    while (!file_exists($stop) && microtime(true) < $deadline && $server->isRunning()) {
        usleep(100000);
    }
} finally {
    $server?->stop();
    $database->close();
    if (file_exists($stop)) {
        unlink($stop);
    }
    echo "CLEANED isolated MySQL database and test storage.\n";
}
