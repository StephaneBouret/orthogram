<?php

declare(strict_types=1);

// Run from the published project root. Never dump the environment or exception text.
// No SQL, no media writes. CLI checks do not prove PHP-FPM configuration/access.
try {
    if (5 !== $argc || !in_array($argv[1], ['source', 'compiled', 'container'], true)) {
        throw new RuntimeException('Invalid arguments');
    }
    require getcwd().'/vendor/autoload.php';
    $dotenv = new Symfony\Component\Dotenv\Dotenv();
    $source = $dotenv->parse(file_get_contents($argv[2]));
    $expected = [
        'COURSE_STORAGE_DIR' => $argv[3],
        'DICTATION_STORAGE_DIR' => $argv[4],
        'DICTATION_LEARNER_ENABLED' => $source['DICTATION_LEARNER_ENABLED'] ?? null,
    ];
    if (!in_array($expected['DICTATION_LEARNER_ENABLED'], ['0', '1'], true)) {
        throw new RuntimeException('Flag must be explicit');
    }
    $check = static function (array $values) use ($expected): void {
        foreach ($expected as $key => $value) {
            if (($values[$key] ?? null) !== $value) {
                throw new RuntimeException('Configuration mismatch');
            }
        }
    };
    $check($source);
    // Do not silently hide a conflicting process override by exporting expected values.
    foreach ($expected as $key => $value) {
        foreach ([$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)] as $actual) {
            if (null !== $actual && false !== $actual && $actual !== $value) {
                throw new RuntimeException('Process override mismatch');
            }
        }
    }
    if (!class_exists(getID3::class)) {
        throw new RuntimeException('Missing getID3');
    }
    if ('source' !== $argv[1]) {
        $check($dotenv->parse(file_get_contents('.env.local')));
        $compiled = require getcwd().'/.env.local.php';
        $check($compiled);
        if ('prod' !== ($compiled['APP_ENV'] ?? null)) {
            throw new RuntimeException('Expected prod dump');
        }
        $dotenv->bootEnv(getcwd().'/.env');
        $check(array_replace($_ENV, $_SERVER));
        if ('prod' !== ($_SERVER['APP_ENV'] ?? null) || ($_SERVER['APP_DEBUG'] ?? '1')) {
            throw new RuntimeException('Expected prod without debug');
        }
    }
    if ('container' === $argv[1]) {
        $kernel = new App\Kernel('prod', false);
        $kernel->boot();
        $container = $kernel->getContainer();
        if ($container->getParameter('app.dictation_storage_dir') !== $expected['DICTATION_STORAGE_DIR']
            || $container->getParameter('app.course_storage_dir') !== $expected['COURSE_STORAGE_DIR']) {
            throw new RuntimeException('Container storage mismatch');
        }
        $kernel->shutdown();
    }
    echo 'Configuration '.$argv[1].' OK (CLI), DICTATION_LEARNER_ENABLED='.$expected['DICTATION_LEARNER_ENABLED'].PHP_EOL;
} catch (Throwable) {
    // Dotenv errors can contain the offending line, including credentials.
    fwrite(STDERR, "STOP: configuration beta invalide, surcharge divergente ou dependances absentes. Verifier les deux stockages, le flag explicite 0/1 et les fichiers d'environnement sans les afficher.\n");
    exit(1);
}
