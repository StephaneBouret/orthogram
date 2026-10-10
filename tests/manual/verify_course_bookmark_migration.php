<?php

declare(strict_types=1);

// No application kernel: the only selected database is newly created and disposable.
use App\Doctrine\Type\UtcDateTimeImmutableType;
use App\Entity\Lesson;
use App\Entity\QuizAttempt;
use App\Tests\Support\QuizPlayerFactory;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Type;
use Doctrine\Migrations\Version\DbalMigrationFactory;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Misd\PhoneNumberBundle\Doctrine\DBAL\Types\PhoneNumberType;
use Psr\Log\NullLogger;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__, 2).'/migrations/Version20261009120000.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL']);
if (!in_array($params['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
    || !in_array($params['driver'] ?? '', ['pdo_mysql', 'mysqli'], true) || isset($params['unix_socket'])) {
    throw new LogicException('Un serveur MySQL/MariaDB local est requis.');
}
unset($params['dbname']);
$server = DriverManager::getConnection($params);
$database = 'orthogram_bookmark_'.bin2hex(random_bytes(8)).'_test';
$created = false;
$connection = null;
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $check(null === $server->fetchOne('SELECT DATABASE()'), 'Aucune base existante ne doit être sélectionnée.');
    printf("Serveur : %s:%s (%s). Cible jetable : %s\n", $params['host'], $params['port'] ?? 3306, $server->fetchOne('SELECT VERSION()'), $database);
    $server->executeStatement('CREATE DATABASE '.$server->quoteIdentifier($database).' CHARACTER SET utf8mb4');
    $created = true;
    $params['dbname'] = $database;
    $connection = DriverManager::getConnection($params);
    $check($database === $connection->fetchOne('SELECT DATABASE()'), 'La connexion doit cibler exactement la base jetable.');
    foreach ([UtcDateTimeImmutableType::NAME => UtcDateTimeImmutableType::class, 'phone_number' => PhoneNumberType::class] as $name => $class) {
        if (!Type::hasType($name)) {
            Type::addType($name, $class);
        }
    }
    $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2).'/src/Entity'], true);
    $config->enableNativeLazyObjects(true);
    $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
    $em = new EntityManager($connection, $config);
    (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    [$user, $course] = QuizPlayerFactory::create($em);
    $courseB = (new App\Entity\Courses())->setName('Cours B')->setSlug('cours-b')->setSection($course->getSection());
    $em->persist($courseB);
    $em->persist((new Lesson())->setUser($user)->setCourse($course)->setName('Progression conservée'));
    $em->persist(new QuizAttempt($user, $course, $course->getQuiz(), ['version' => 1, 'title' => 'Tentative conservée', 'questions' => []]));
    $em->flush();
    $em->clear();

    // Reconstruct the previous schema with populated tables before running up().
    foreach ($connection->createSchemaManager()->listTableForeignKeys('user') as $fk) {
        if (['last_visited_course_id'] === $fk->getLocalColumns()) {
            $connection->executeStatement('ALTER TABLE user DROP FOREIGN KEY '.$connection->quoteIdentifier($fk->getName()));
        }
    }
    $connection->executeStatement('ALTER TABLE user DROP last_visited_course_id, DROP course_bookmark_version');
    $snapshot = static function () use ($connection): array {
        $rows = [];
        foreach ($connection->createSchemaManager()->listTableNames() as $table) {
            $rows[$table] = $connection->fetchAllAssociative('SELECT * FROM '.$connection->quoteIdentifier($table).' ORDER BY id');
        }

        return $rows;
    };
    $before = $snapshot();
    $factory = new DbalMigrationFactory($connection, new NullLogger());
    $migrate = static function (string $direction) use ($factory, $connection): void {
        $migration = $factory->createVersion('DoctrineMigrations\\Version20261009120000');
        $migration->$direction(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    };
    $migrate('up');
    $after = $snapshot();
    foreach ($after['user'] as &$row) {
        $check(null === $row['last_visited_course_id'], 'Les comptes existants doivent commencer sans marque-page.');
        $check(0 === (int) $row['course_bookmark_version'], 'La version initiale doit être zéro.');
        unset($row['last_visited_course_id'], $row['course_bookmark_version']);
    }
    unset($row);
    $check($before === $after, 'Toutes les données préexistantes doivent être conservées.');
    $connection->update('user', ['last_visited_course_id' => $course->getId()], ['id' => $user->getId()]);
    $check((int) $connection->fetchOne('SELECT last_visited_course_id FROM user WHERE id = ?', [$user->getId()]) === $course->getId(), 'La référence doit être persistée.');
    $updateSql = 'UPDATE user SET last_visited_course_id = ?, course_bookmark_version = course_bookmark_version + 1 WHERE id = ? AND course_bookmark_version = ?';
    $check(1 === $connection->executeStatement($updateSql, [$courseB->getId(), $user->getId(), 0]), 'B doit être accepté.');
    $check(0 === $connection->executeStatement($updateSql, [$course->getId(), $user->getId(), 0]), 'A obsolète doit être refusé.');
    $check((int) $connection->fetchOne('SELECT last_visited_course_id FROM user WHERE id = ?', [$user->getId()]) === $courseB->getId(), 'B doit rester enregistré.');
    $check(1 === $connection->executeStatement($updateSql, [$course->getId(), $user->getId(), 1]), 'La version courante doit être acceptée.');
    echo "OK MySQL : B accepté, A obsolète refusé sans écraser B, écriture sur version courante acceptée.\n";
    $migrate('down');
    $check($before === $snapshot(), 'Down ne doit supprimer que le marque-page.');
    $migrate('up');
    $connection->update('user', ['last_visited_course_id' => $course->getId()], ['id' => $user->getId()]);
    // Delete through ORM, as in the administration, to handle existing lessons.
    $em->remove($em->find(App\Entity\Courses::class, $course->getId()));
    $em->flush();
    $check(null === $connection->fetchOne('SELECT last_visited_course_id FROM user WHERE id = ?', [$user->getId()]), 'La suppression du cours doit annuler la référence.');
    $check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM user'), 'La suppression du cours doit conserver le compte.');
    $other = QuizPlayerFactory::user('delete@example.test');
    $otherCourse = (new App\Entity\Courses())->setName('Cours conservé')->setSlug('cours-conserve')
        ->setSection($em->find(App\Entity\Sections::class, $course->getSection()->getId()));
    $em->persist($otherCourse);
    $other->setLastVisitedCourse($otherCourse);
    $em->persist($other);
    $em->flush();
    $em->remove($other);
    $em->flush();
    $check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM courses WHERE id = ?', [$otherCourse->getId()]), 'Supprimer un compte doit conserver son dernier cours.');
    echo "OK : up/down/up, données existantes conservées, référence nullable, suppression du cours sans suppression du compte, suppression de compte.\n";
} finally {
    $connection?->close();
    if ($created && 1 === preg_match('/^orthogram_bookmark_[a-f0-9]{16}_test$/D', $database)) {
        $server->executeStatement('DROP DATABASE '.$server->quoteIdentifier($database));
        echo "Base jetable supprimée : $database\n";
    }
    $server->close();
}
