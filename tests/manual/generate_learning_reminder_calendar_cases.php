<?php

declare(strict_types=1);

use App\Controller\Course\ProgramSummaryController;
use App\Dto\LearningReminderPayload;
use App\Services\LearningReminderCalendarService;
use App\Services\LearningReminderIcalendarWriter;
use App\Services\LearningReminderNextRunCalculator;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

// Manual CLI support only: no Kernel, Dotenv, database, mail or recurrence oracle.
function expectedOccurrence(string $label, string $localStart, string $localEnd, string $utcStart, string $utcEnd): array
{
    return [
        'label' => $label,
        'local_start' => $localStart,
        'local_end' => $localEnd,
        'utc_start' => $utcStart,
        'utc_end' => $utcEnd,
        'duration_seconds' => 900,
        'expected_count_at_this_start' => 1,
    ];
}

function writeExactNewFile(string $path, string $contents): void
{
    // Binary, exclusive creation: neither platform newline conversion nor overwrite.
    $handle = fopen($path, 'xb');
    if (false === $handle) {
        throw new RuntimeException('Impossible de créer : '.$path);
    }
    try {
        $offset = 0;
        while ($offset < strlen($contents)) {
            $written = fwrite($handle, substr($contents, $offset));
            if (false === $written || 0 === $written) {
                throw new RuntimeException('Écriture incomplète : '.$path);
            }
            $offset += $written;
        }
    } finally {
        fclose($handle);
    }
    if (file_get_contents($path) !== $contents) {
        throw new RuntimeException('Relecture différente des octets fournis : '.$path);
    }
}

try {
    if ('cli' !== PHP_SAPI) {
        throw new RuntimeException('Exécution CLI uniquement.');
    }
    if (2 !== $argc || !str_starts_with($argv[1], '--base-uri=')) {
        throw new InvalidArgumentException('Usage : php tests/manual/generate_learning_reminder_calendar_cases.php --base-uri="https://127.0.0.1:8000"');
    }
    $baseUri = substr($argv[1], strlen('--base-uri='));
    $uri = parse_url($baseUri);
    if (false === $uri || !in_array($uri['scheme'] ?? '', ['http', 'https'], true)
        || empty($uri['host']) || preg_match('/[\x00-\x20\x7f]/', $baseUri)
        || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($uri))) {
        throw new InvalidArgumentException('Fournir une origine HTTP(S), avec chemin de base éventuel, sans identifiants, query ni fragment.');
    }

    $root = dirname(__DIR__, 2);
    require $root.'/vendor/autoload.php';

    // Read the existing route attributes without instantiating its controller.
    $routes = (new AttributeRouteControllerLoader())->load(ProgramSummaryController::class);
    $generator = new UrlGenerator($routes, RequestContext::fromUri($baseUri));
    $trainingUrl = $generator->generate('app_user_training', [], UrlGeneratorInterface::ABSOLUTE_URL);
    $service = new LearningReminderCalendarService(
        new LearningReminderNextRunCalculator(),
        new LearningReminderIcalendarWriter(),
        $generator,
        $baseUri,
    );

    // Fixed manual expectations, never derived from expansion of the generated ICS.
    $cases = [
        'B' => [
            'file' => 'B-premiere-heure-ambigue.ics',
            'reference_utc' => '2026-10-25T01:00:00Z',
            'payload' => new LearningReminderPayload('weekly', '02:30', [7], null, 'Europe/Paris'),
            'expected_initial_recurrence_id' => null,
            'expected_series_dtstart' => '20261101T023000',
            'expected_distinct_uids' => 2,
            'expected_until_utc' => '2031-10-25T01:30:00Z',
            'expected_occurrences' => [
                expectedOccurrence('Premier événement séparé : second passage Orthogram', '2026-10-25T02:30:00+01:00', '2026-10-25T02:45:00+01:00', '2026-10-25T01:30:00Z', '2026-10-25T01:45:00Z'),
                expectedOccurrence('Dimanche suivant', '2026-11-01T02:30:00+01:00', '2026-11-01T02:45:00+01:00', '2026-11-01T01:30:00Z', '2026-11-01T01:45:00Z'),
            ],
            'forbidden_starts_utc' => ['2026-10-25T00:30:00Z'],
        ],
    ];

    $outputDirectory = $root.'/var/calendar-recette/correction-b';
    $names = [...array_column($cases, 'file'), 'manifest.json'];
    foreach ($names as $name) {
        if (file_exists($outputDirectory.'/'.$name) || is_link($outputDirectory.'/'.$name)) {
            throw new RuntimeException('Lot existant, aucun écrasement autorisé : '.$name.'. Conserver ce lot pendant les imports.');
        }
    }

    $manifest = [
        'schema_version' => 3,
        'batch' => 'correction-b',
        'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'base_uri_argument' => $baseUri,
        'training_url' => $trainingUrl,
        'php_version' => PHP_VERSION,
        'php_timezone_database_version' => timezone_version_get(),
        'duration_seconds' => 900,
        'horizon' => 'Cinq années civiles UTC depuis le premier début, borne des débuts inclusive ; 29 février ramené au dernier jour de février si nécessaire.',
        'automated_scope' => 'Génération et relecture identique octet pour octet uniquement ; aucune expansion ni validation des imports.',
        'manual_imports_status' => 'non exécutés',
        'source_sha256' => [],
        'preserved_batches_sha256' => [],
        'cases' => [],
    ];
    // Fingerprint both complete older lots before any output is written.
    $previousNames = ['A-premiere-heure-inexistante.ics', 'B-premiere-heure-ambigue.ics', 'C-duree-transition-printemps.ics', 'D-duree-transition-automne.ics', 'manifest.json'];
    foreach (['initial' => '', 'correction-a' => '/correction-a'] as $batch => $suffix) {
        foreach ($previousNames as $name) {
            $original = $root.'/var/calendar-recette'.$suffix.'/'.$name;
            if (!is_file($original)) {
                throw new RuntimeException('Fichier du lot antérieur manquant : '.$original);
            }
            $manifest['preserved_batches_sha256'][$batch][$name] = hash_file('sha256', $original);
        }
    }
    foreach (['tests/manual/generate_learning_reminder_calendar_cases.php', 'src/Services/LearningReminderCalendarService.php', 'src/Services/LearningReminderIcalendarWriter.php', 'src/Services/LearningReminderNextRunCalculator.php', 'src/Controller/Course/ProgramSummaryController.php', 'composer.lock'] as $source) {
        $manifest['source_sha256'][$source] = hash_file('sha256', $root.'/'.$source);
    }
    $files = [];
    foreach ($cases as $id => $case) {
        $prepared = $service->prepare($case['payload'], new DateTimeImmutable($case['reference_utc']));
        $ics = $prepared['ics'];
        // Read metadata only. Never unfold, reserialize, patch or repair this string.
        preg_match_all('/^UID:([^\r\n]+)\r?$/m', $ics, $uids);
        $files[$case['file']] = $ics;
        $manifest['cases'][$id] = [
            ...$case,
            'bytes' => strlen($ics),
            'sha256' => hash('sha256', $ics),
            'observed_uid_per_vevent' => $uids[1],
            'preparation_expires_at' => $prepared['expiresAt'],
            'notice' => $prepared['notice'],
            'separate_first_notice' => $prepared['separateFirstNotice'],
            'manual_status' => 'non exécuté',
        ];
    }
    $files['manifest.json'] = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException('Impossible de créer : '.$outputDirectory);
    }
    foreach ($files as $name => $contents) {
        writeExactNewFile($outputDirectory.'/'.$name, $contents);
        fwrite(STDOUT, $name.' : '.strlen($contents).' octets, SHA-256 '.hash('sha256', $contents).PHP_EOL);
    }
    foreach (['initial' => '', 'correction-a' => '/correction-a'] as $batch => $suffix) {
        foreach ($manifest['preserved_batches_sha256'][$batch] as $name => $hash) {
            if (hash_file('sha256', $root.'/var/calendar-recette'.$suffix.'/'.$name) !== $hash) {
                throw new RuntimeException('Le lot antérieur a changé : '.$batch.'/'.$name);
            }
        }
    }
    fwrite(STDOUT, 'OK : B identique au retour prepare(), manifeste relu ; dix empreintes antérieures inchangées. Imports manuels non exécutés.'.PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'ÉCHEC : '.$error->getMessage().PHP_EOL.'En cas d’erreur d’écriture, le lot peut être incomplet ; ne pas importer sans le fichier B et son manifeste.'.PHP_EOL);
    exit(1);
}
