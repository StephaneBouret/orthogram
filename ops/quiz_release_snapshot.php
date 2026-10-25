<?php

declare(strict_types=1);

// Run from the published project root. Private JSON evidence may contain personal data.
// No DDL/DML, migration, import or activation is ever executed by this script.
require dirname(__DIR__).'/vendor/autoload.php';
$options = getopt('', ['capture', 'before:', 'after:', 'phase:', 'plan:', 'output:', 'expect-env:', 'expect-host:', 'expect-port:', 'expect-server:', 'expect-database:']);
$kernel = null;
try {
    $file = $options['output'] ?? null;
    App\Services\ModuleOneReserveManifest::require(is_string($file) && !file_exists($file) && is_dir(dirname($file)), 'Fichier de sortie nouveau dans un répertoire privé existant requis.');
    $audit = new App\Services\QuizReleaseSnapshot();
    $read = static function (string $path): array { return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); };
    if (array_key_exists('capture', $options)) {
        (new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__).'/.env');
        App\Services\ModuleOneReserveManifest::require(($_SERVER['APP_ENV'] ?? '') === 'prod' && ($options['expect-env'] ?? '') === 'prod', 'Capture SSH réservée à APP_ENV=prod avec --expect-env=prod.');
        $kernel = new App\Kernel('prod', false);
        $kernel->boot();
        $db = $kernel->getContainer()->get('doctrine')->getConnection();
        $destination = (new App\Services\QuizCommandDestination($kernel->getEnvironment()))->verify($db, $options);
        $report = ['destination' => $destination, 'tables' => $audit->capture($db)];
    } else {
        $before = $read($options['before'] ?? '');
        $after = $read($options['after'] ?? '');
        App\Services\ModuleOneReserveManifest::require($before['destination'] === $after['destination'], 'Destinations des relevés différentes.');
        $plan = isset($options['plan']) ? $read($options['plan']) : [];
        if ([] !== $plan) {
            App\Services\ModuleOneReserveManifest::require(($plan['destination'] ?? null) === $before['destination'], 'Plan destiné à une autre base.');
        }
        $report = $audit->compare($before['tables'], $after['tables'], $options['phase'] ?? '', $plan);
    }
    $handle = fopen($file, 'x');
    if (false === $handle) {
        throw new RuntimeException('Output unavailable');
    }
    fwrite($handle, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    fclose($handle);
    echo "Relevé/comparaison enregistré ; aucune écriture SQL.\n";
} catch (Throwable $error) {
    fwrite(STDERR, ($error instanceof DomainException ? $error->getMessage() : 'Erreur technique ; aucune correction automatique.')."\n");
    exit(1);
} finally {
    $kernel?->shutdown();
}
