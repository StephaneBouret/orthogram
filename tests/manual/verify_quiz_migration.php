<?php

declare(strict_types=1);

// Applies only the quiz migration, in a new disposable LOCAL MySQL/MariaDB database.
// Never selects or modifies the configured application database.
use App\Kernel;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Version\DbalMigrationFactory;
use Psr\Log\NullLogger;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__, 2).'/migrations/Version20260913110000.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel('test', true);
$kernel->boot();
$params = $kernel->getContainer()->get('doctrine')->getConnection()->getParams();
if (!in_array($params['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
    || !in_array($params['driver'] ?? '', ['pdo_mysql', 'mysqli'], true)) {
    throw new LogicException('La vérification requiert une connexion MySQL/MariaDB locale.');
}
unset($params['dbname'], $params['url']);
$server = DriverManager::getConnection($params);
$database = 'orthogram_quiz_migration_'.bin2hex(random_bytes(8)).'_test';
$quotedDatabase = $server->quoteIdentifier($database);
$created = false;
$connection = null;
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $server->executeStatement('CREATE DATABASE '.$quotedDatabase.' CHARACTER SET utf8mb4');
    $created = true;
    $params['dbname'] = $database;
    $connection = DriverManager::getConnection($params);
    $check($connection->getDatabase() === $database, 'La connexion doit cibler la base jetable.');
    $connection->executeStatement('CREATE TABLE courses (id INT AUTO_INCREMENT NOT NULL PRIMARY KEY, name VARCHAR(255) NOT NULL)');
    $connection->insert('courses', ['name' => 'Cours existant']);
    $before = $connection->fetchAllAssociative('SELECT * FROM courses');

    $factory = new DbalMigrationFactory($connection, new NullLogger());
    $migration = $factory->createVersion('DoctrineMigrations\\Version20260913110000');
    $migration->up(new Schema());
    foreach ($migration->getSql() as $query) {
        $connection->executeStatement($query->getStatement());
    }
    $check(null === $connection->fetchOne('SELECT quiz_id FROM courses WHERE id = 1'), 'Le cours existant doit rester sans quiz.');
    $connection->insert('quiz', ['title' => 'Quiz temporaire']);
    $connection->insert('quiz_question', ['quiz_id' => 1, 'title' => 'Question', 'explanation' => '', 'multiple' => 0, 'position' => 0]);
    $connection->insert('quiz_answer', ['question_id' => 1, 'content' => 'Réponse', 'correct' => 1, 'position' => 0]);
    $connection->update('courses', ['quiz_id' => 1], ['id' => 1]);
    $connection->delete('quiz', ['id' => 1]);
    $check(0 === (int) $connection->fetchOne('SELECT COUNT(*) FROM quiz_question'), 'Suppression en cascade des questions attendue.');
    $check(0 === (int) $connection->fetchOne('SELECT COUNT(*) FROM quiz_answer'), 'Suppression en cascade des propositions attendue.');
    $check(null === $connection->fetchOne('SELECT quiz_id FROM courses WHERE id = 1'), 'Le cours doit être conservé avec quiz_id NULL.');

    $migration = $factory->createVersion('DoctrineMigrations\\Version20260913110000');
    $migration->down(new Schema());
    foreach ($migration->getSql() as $query) {
        $connection->executeStatement($query->getStatement());
    }
    $check($before === $connection->fetchAllAssociative('SELECT * FROM courses'), 'Le retour arrière doit conserver les cours.');
    $check(['courses'] === $connection->createSchemaManager()->listTableNames(), 'Les trois tables de quiz doivent être supprimées.');
    echo "OK : migration up/down, cascades et conservation du cours vérifiés dans $database.\n";
} finally {
    $connection?->close();
    if ($created && 1 === preg_match('/^orthogram_quiz_migration_[a-f0-9]{16}_test$/D', $database)) {
        $server->executeStatement('DROP DATABASE '.$quotedDatabase);
        echo "Base de vérification supprimée.\n";
    }
    $server->close();
    $kernel->shutdown();
}
