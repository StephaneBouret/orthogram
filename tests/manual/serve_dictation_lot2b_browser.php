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
$stop = dirname(__DIR__, 2).'/var/dictation-2b-browser.stop';
if (file_exists($stop)) {
    $database->close();
    throw new LogicException('Ancien témoin présent : vérifier la recette précédente.');
}
try {
    $database->install();
    $em = $database->manager();
    [$user, , $source] = App\Tests\Support\DictationFactory::create($em);
    $user->setRoles(['ROLE_ADMIN']);
    $em->flush();
    $editorial = new App\Services\Dictation\DictationEditorialService($em, new App\Services\Dictation\DictationCorrector(), App\Tests\Support\DictationAudioFixture::storage());
    $draft = $editorial->cloneVersion($source, $source->getRevision());
    if (in_array('--long-audio', $argv, true)) {
        $editorial->importAudio($draft, $draft->getRevision(), 'writing', App\Tests\Support\DictationAudioFixture::longUpload());
        $draft->editSegments([['start' => 0, 'end' => 1000]]);
        $editorial->saveDraft($draft, $draft->getRevision());
    }
    $server = new Symfony\Component\Process\Process([PHP_BINARY, '-d', 'xdebug.mode=off', '-S', '127.0.0.1:8815', '-t', 'public', 'tests/manual/dictation_lot2b_browser_router.php'], dirname(__DIR__, 2), [
        'APP_ENV' => 'test', 'APP_DEBUG' => '1', 'DATABASE_URL' => $database->kernelUrl(),
        'DICTATION_BROWSER_DB' => $database->name, 'DICTATION_BROWSER_USER' => (string) $user->getId(),
        'DICTATION_STORAGE_DIR' => $_SERVER['DICTATION_STORAGE_DIR'], 'COURSE_STORAGE_DIR' => $_SERVER['COURSE_STORAGE_DIR'],
    ]);
    $server->setTimeout(null);
    $server->start();
    echo 'READY http://127.0.0.1:8815/admin/dictees/versions/'.$draft->getId().PHP_EOL;
    echo 'Stop: create var/dictation-2b-browser.stop (automatic timeout: 15 minutes).'.PHP_EOL;
    $deadline = microtime(true) + 900;
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
