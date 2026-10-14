<?php

declare(strict_types=1);

// Isolated, synthetic CLI measurements; no kernel, database, external service or media.
use App\Services\Dictation\DictationCorrector;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$case = $argv[1] ?? 'words';
[$reference, $copy, $variants] = match ($case) {
    'words' => [implode(' ', array_fill(0, 500, 'a')), implode(' ', array_fill(0, 750, 'b')), []],
    'repeated' => [implode(' ', array_fill(0, 500, 'a')), implode(' ', array_fill(0, 750, 'a')), []],
    'punctuation' => ['a'.str_repeat('!', 19999), 'a'.str_repeat('?', 19999), []],
    'length' => [str_repeat('漢', 20000), str_repeat('漢', 20000), []],
    'combined' => [implode(' ', array_fill(0, 500, str_repeat('漢', 39))), implode(' ', array_fill(0, 750, str_repeat('文', 25))), []],
    'elisions' => [implode(' ', array_fill(0, 250, 'l’école')), implode(' ', array_fill(0, 375, "l' école")), []],
    'variants' => [implode(' ', array_fill(0, 500, 'a')), implode(' ', array_fill(0, 750, 'b')),
        array_map(static fn (int $i): array => ['referenceIndex' => $i, 'alternatives' => ['b', 'b b', 'b b b', 'c']], range(0, 31))],
    default => throw new InvalidArgumentException('Unknown synthetic case.'),
};
$baseline = memory_get_usage(true);
memory_reset_peak_usage();
$start = hrtime(true);
$result = (new DictationCorrector())->correct($reference, $copy, $variants);
echo json_encode(['case' => $case, 'php' => PHP_VERSION, 'xdebug' => extension_loaded('xdebug') ? ini_get('xdebug.mode') : false,
    'seconds' => round((hrtime(true) - $start) / 1e9, 4),
    'peakMiB' => round(memory_get_peak_usage(true) / 1048576, 2),
    'additionalPeakMiB' => round((memory_get_peak_usage(true) - $baseline) / 1048576, 2),
    'lexicalErrors' => $result->lexicalErrorCount, 'punctuationErrors' => $result->punctuationErrorCount,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
