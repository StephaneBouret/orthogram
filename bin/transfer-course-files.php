#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\Courses\CourseFileTransfer;

require dirname(__DIR__).'/vendor/autoload.php';

$options = getopt('', ['source-files:', 'source-audios:', 'source-videos:', 'destination:', 'copy', 'verify-only']);
foreach (['source-files', 'source-audios', 'source-videos', 'destination'] as $required) {
    if (!isset($options[$required]) || !is_string($options[$required]) || '' === $options[$required]) {
        fwrite(STDERR, "Usage: php bin/transfer-course-files.php --source-files=ABS --source-audios=ABS --source-videos=ABS --destination=ABS [--copy|--verify-only]\nDefault: dry run. Sources are NEVER removed.\n");
        exit(2);
    }
}
try {
    $messages = (new CourseFileTransfer())->run([
        'files' => $options['source-files'], 'audios' => $options['source-audios'], 'videos' => $options['source-videos'],
    ], $options['destination'], isset($options['copy']), isset($options['verify-only']));
    foreach ($messages as $message) {
        echo $message."\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
