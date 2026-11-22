<?php

use App\Tests\Support\DictationTestDatabase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (2 !== $argc) {
    fwrite(STDERR, "Usage : php tests/manual/preview_quiz_manifest.php chemin/manifeste.json\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv($root.'/.env');
$database = new DictationTestDatabase(recipe: 'editorial');
try {
    $database->install();
    $db = $database->manager()->getConnection();
    $preview = new Process([PHP_BINARY, $root.'/bin/console', 'app:quiz:manifest', '--env=test',
        '--expect-env=test', '--expect-database='.$database->name, '--expect-host='.$db->getParams()['host'],
        '--expect-port='.$db->getParams()['port'], '--expect-server='.$db->fetchOne('SELECT @@hostname'), '--', $argv[1]],
        getcwd(), ['DATABASE_URL' => $database->kernelUrl(), 'SYMFONY_DOTENV_VARS' => false]);
    $status = $preview->run(static function (string $type, string $bytes): void {
        fwrite(Process::ERR === $type ? STDERR : STDOUT, $bytes);
    });
} finally {
    $database->close();
}
exit($status);
