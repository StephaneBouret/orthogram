<?php

declare(strict_types=1);

// Run the whole suite against a fresh local MySQL database, never the configured database.
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

$project = dirname(__DIR__, 2);
require $project.'/vendor/autoload.php';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv($project.'/.env');
$url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'];
$params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($url);
if (!in_array($params['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
    || !in_array($params['driver'] ?? '', ['pdo_mysql', 'mysqli'], true) || isset($params['unix_socket'])) {
    throw new LogicException('Un serveur MySQL/MariaDB local est requis.');
}
unset($params['dbname']);
$server = DriverManager::getConnection($params);
$base = 'orthogram_bookmark_suite_'.bin2hex(random_bytes(8));
$database = $base.'_test';
$created = false;
$kernel = null;
$exitCode = 1;
try {
    if (null !== $server->fetchOne('SELECT DATABASE()')) {
        throw new LogicException('Aucune base existante ne doit être sélectionnée.');
    }
    $server->executeStatement('CREATE DATABASE '.$server->quoteIdentifier($database).' CHARACTER SET utf8mb4');
    $created = true;
    // Symfony adds _test in the test environment.
    $testUrl = preg_replace('~/[^/?]+(?=\?|$)~', '/'.$base, $url);
    $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $testUrl;
    $_SERVER['TEST_TOKEN'] = $_ENV['TEST_TOKEN'] = '';
    $kernel = new App\Kernel('test', true);
    $kernel->boot();
    $em = $kernel->getContainer()->get('doctrine')->getManager();
    if (!$em instanceof EntityManagerInterface || $database !== $em->getConnection()->fetchOne('SELECT DATABASE()')) {
        throw new LogicException('La base effective doit être exactement la base jetable.');
    }
    printf("Suite complète : base isolée confirmée %s\n", $database);
    (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    $kernel->shutdown();
    // Dotenv must treat the isolated URL as an explicit process override,
    // not as a previously loaded .env value that it may replace.
    $environment = ['DATABASE_URL' => $testUrl, 'APP_ENV' => 'test', 'KERNEL_CLASS' => App\Kernel::class,
        'TEST_TOKEN' => '', 'SYMFONY_DOTENV_VARS' => false, 'BOOKMARK_TEST_DATABASE' => $database, 'XDEBUG_MODE' => 'off'];
    $preflight = new Process([PHP_BINARY, '-r', <<<'PHP'
        require 'vendor/autoload.php';
        (new Symfony\Component\Dotenv\Dotenv())->bootEnv('.env');
        $kernel = new App\Kernel('test', true);
        $kernel->boot();
        $connection = $kernel->getContainer()->get('doctrine')->getConnection();
        if (getenv('BOOKMARK_TEST_DATABASE') !== $connection->fetchOne('SELECT DATABASE()')
            || !$connection->createSchemaManager()->introspectTable('user')->hasColumn('last_visited_course_id')) {
            throw new LogicException('Le sous-processus doit utiliser la base jetable avec le nouveau schéma.');
        }
        echo "Cible et schéma du sous-processus confirmés.\n";
        $kernel->shutdown();
        PHP], $project, $environment);
    $preflight->mustRun();
    echo $preflight->getOutput();
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', 'vendor/bin/phpunit', '--no-progress'], $project,
        $environment, timeout: 600);
    $exitCode = $process->run(static function (string $type, string $buffer): void { echo $buffer; });
} finally {
    $kernel?->shutdown();
    if ($created && 1 === preg_match('/^orthogram_bookmark_suite_[a-f0-9]{16}_test$/D', $database)) {
        $server->executeStatement('DROP DATABASE '.$server->quoteIdentifier($database));
        echo "Base jetable supprimée : $database\n";
    }
    $server->close();
}
exit($exitCode);
