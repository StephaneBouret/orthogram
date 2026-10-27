<?php

declare(strict_types=1);

// Offline only: no kernel, DB connection, or mutation. Captures use quiz_release_snapshot.php.
require dirname(__DIR__).'/vendor/autoload.php';
$options = getopt('', ['before:', 'after:', 'phase:', 'plan:', 'output:']);
try {
    $output = $options['output'] ?? null;
    App\Services\ModuleTwoQuizManifest::require(is_string($output) && !file_exists($output) && is_dir(dirname($output)), 'Sortie nouvelle dans un répertoire privé existant requise.');
    $read = static fn (string $file): array => json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $report = (new App\Services\ModuleTwoQuizSnapshot())->compare($read($options['before'] ?? ''), $read($options['after'] ?? ''), $options['phase'] ?? '', isset($options['plan']) ? $read($options['plan']) : []);
    $handle = fopen($output, 'x');
    if (false === $handle) {
        throw new RuntimeException('Output unavailable');
    }
    fwrite($handle, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    fclose($handle);
    echo "Conservation vérifiée ; aucune connexion SQL.\n";
} catch (Throwable $error) {
    fwrite(STDERR, ($error instanceof DomainException ? $error->getMessage() : 'Comparaison impossible ; aucune correction automatique.')."\n");
    exit(1);
}
