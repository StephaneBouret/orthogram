<?php

declare(strict_types=1);

// Only spawned by the isolated integration tests; never a development connection.
if ('cli' !== PHP_SAPI || 7 !== count($argv)) {
    exit(1);
}
$storage = realpath($argv[6]);
if (false === $storage || !str_starts_with($storage, realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'orthogram-dictation-tests-')) {
    throw new LogicException('Isolated fixture storage required.');
}
$_SERVER['DICTATION_STORAGE_DIR'] = $_ENV['DICTATION_STORAGE_DIR'] = $storage;
require dirname(__DIR__).'/bootstrap.php';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$database = new App\Tests\Support\DictationTestDatabase($argv[1]);
try {
    $em = $database->manager();
    $version = $em->find(App\Entity\DictationVersion::class, (int) $argv[3]) ?? throw new LogicException('Fixture missing.');
    $service = new App\Services\Dictation\DictationEditorialService($em, new App\Services\Dictation\DictationCorrector(), App\Tests\Support\DictationAudioFixture::storage());
    echo 'READY '.$em->getConnection()->fetchOne('SELECT CONNECTION_ID()').PHP_EOL;
    flush();
    try {
        if ('delete' === $argv[5]) {
            $service->deleteDraft((int) $argv[2], (int) $argv[3], (int) $argv[4]);
        } elseif ('publish' === $argv[5]) {
            $service->publish($version, (int) $argv[4]);
        } else {
            throw new LogicException('Unexpected operation.');
        }
        echo "RESULT 200\n";
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
        echo 'RESULT '.$error->getStatusCode().PHP_EOL;
    }
} finally {
    $database->close();
}
