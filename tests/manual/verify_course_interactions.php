<?php

declare(strict_types=1);

// Lot 2A regressions: every database write targets a disposable database.
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'];
$params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($url);
if (!in_array($params['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
    || !in_array($params['driver'] ?? '', ['pdo_mysql', 'mysqli'], true) || isset($params['unix_socket'])) {
    throw new LogicException('Un serveur MySQL/MariaDB local est requis.');
}
unset($params['dbname']);
$server = DriverManager::getConnection($params);
$base = 'orthogram_interactions_'.bin2hex(random_bytes(8));
$database = $base.'_test';
$quotedDatabase = $server->getDatabasePlatform()->quoteSingleIdentifier($database);
$created = false;
$kernel = null;
$status = 1;
try {
    if (null !== $server->fetchOne('SELECT DATABASE()')) {
        throw new LogicException('La connexion serveur ne doit sélectionner aucune base existante.');
    }
    echo "Base isolée prévue : $database ; aucune base existante sélectionnée.\n";
    $server->executeStatement('CREATE DATABASE '.$quotedDatabase.' CHARACTER SET utf8mb4');
    $created = true;
    // Doctrine adds its test suffix. Preserve credentials and URL options without printing them.
    $parts = parse_url($url);
    $testUrl = substr($url, 0, strpos($url, $parts['path'], strpos($url, '://') + 3)).'/'.$base;
    if (isset($parts['query'])) {
        $testUrl .= '?'.$parts['query'];
    }
    $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = $testUrl;
    $_ENV['TEST_TOKEN'] = $_SERVER['TEST_TOKEN'] = '';
    $kernel = new App\Kernel('test', true);
    $kernel->boot();
    $em = $kernel->getContainer()->get('test.service_container')->get(EntityManagerInterface::class);
    if ($database !== $em->getConnection()->getDatabase() || $database !== $em->getConnection()->fetchOne('SELECT DATABASE()')) {
        throw new LogicException('La connexion ORM ne cible pas la base isolée attendue.');
    }
    echo "Cible ORM confirmée avant création du schéma : $database\n";
    (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    $kernel->shutdown();
    $kernel = null;
    $process = new Process([
        PHP_BINARY, '-d', 'xdebug.mode=off', 'vendor/phpunit/phpunit/phpunit', '--stop-on-error',
        'tests/Security/Voter/CourseVoterTest.php',
        'tests/Controller/Course', 'tests/Controller/QuizResultsTest.php',
        'tests/Controller/Admin/CoursesFreeAccessTest.php',
        'tests/Services', 'tests/Repository/LearningReminderRepositoryTest.php',
        'tests/Command/SendLearningRemindersCommandTest.php', 'tests/Templates/LearningReminderEmailTest.php',
    ], dirname(__DIR__, 2), array_merge((new Dotenv())->parse(file_get_contents(dirname(__DIR__, 2).'/.env.test')), [
        'DATABASE_URL' => $testUrl, 'TEST_TOKEN' => '', 'APP_ENV' => 'test',
        'KERNEL_CLASS' => App\Kernel::class, 'MAILER_DSN' => 'null://null', 'SYMFONY_DOTENV_VARS' => false,
    ]));
    $process->setTimeout(300);
    $status = $process->run(static function (string $type, string $output): void { echo $output; });
} finally {
    $kernel?->shutdown();
    if ($created) {
        $server->executeStatement('DROP DATABASE '.$quotedDatabase);
        echo "Base isolée supprimée : $database\n";
    }
    $server->close();
}
exit($status);
