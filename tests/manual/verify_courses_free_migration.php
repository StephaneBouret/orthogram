<?php

declare(strict_types=1);

// Only a newly created local database is used. No application kernel or migration runner is booted.
use App\Doctrine\Type\UtcDateTimeImmutableType;
use App\Entity\Comment;
use App\Entity\Courses;
use App\Entity\Exercice;
use App\Entity\ExerciceAttempt;
use App\Entity\Lesson;
use App\Entity\QuizAttempt;
use App\Enum\CourseContentType;
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
require dirname(__DIR__, 2).'/migrations/Version20261001120000.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL']);
if (!in_array($params['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
    || !in_array($params['driver'] ?? '', ['pdo_mysql', 'mysqli'], true)
    || isset($params['unix_socket'])) {
    throw new LogicException('La vérification requiert un serveur MySQL/MariaDB local.');
}
unset($params['dbname']);
$server = DriverManager::getConnection($params);
$database = 'orthogram_courses_free_'.bin2hex(random_bytes(8)).'_test';
$created = false;
$connection = null;
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $check(null === $server->fetchOne('SELECT DATABASE()'), 'La connexion serveur ne doit sélectionner aucune base existante.');
    printf("Serveur : %s:%s (%s). Base sélectionnée : aucune. Cible jetable : %s\n", $params['host'], $params['port'] ?? 3306, $server->fetchOne('SELECT VERSION()'), $database);
    $server->executeStatement('CREATE DATABASE '.$server->quoteIdentifier($database).' CHARACTER SET utf8mb4');
    $created = true;
    $params['dbname'] = $database;
    $connection = DriverManager::getConnection($params);
    $check($database === $connection->getParams()['dbname'] && $database === $connection->fetchOne('SELECT DATABASE()'), 'La connexion doit cibler exactement la base jetable.');
    echo "Cible réelle confirmée avant création des tables : $database\n";

    if (!Type::hasType(UtcDateTimeImmutableType::NAME)) {
        Type::addType(UtcDateTimeImmutableType::NAME, UtcDateTimeImmutableType::class);
    }
    if (!Type::hasType('phone_number')) {
        Type::addType('phone_number', PhoneNumberType::class);
    }
    $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2).'/src/Entity'], true);
    $config->enableNativeLazyObjects(true);
    $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
    $em = new EntityManager($connection, $config);
    (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    [$user, $quizCourse] = QuizPlayerFactory::create($em);
    $exercise = (new Exercice())->setTitle('Exercice préexistant')->setInstruction('Instruction à conserver');
    $em->persist($exercise);
    foreach (CourseContentType::cases() as $position => $type) {
        if (CourseContentType::Quiz === $type) {
            $quizCourse->setPosition($position);
            continue;
        }
        $course = (new Courses())->setName('Cours '.$type->value)->setSlug('cours-'.$type->value)
            ->setContentType($type)->setSection($quizCourse->getSection())->setPosition($position)
            ->setShortDescription('Contenu conservé')->setCorrectionText('Correction conservée')->setDurationMinutes(12)
            ->setPartialFileName('existant.html.twig')->setAudioFileName('existant.mp3')->setVideoName('existant.mp4');
        if (CourseContentType::Exercise === $type) {
            $course->setExercice($exercise);
        }
        $em->persist($course);
    }
    $em->persist((new Lesson())->setName('Progression conservée')->setUser($user)->setCourse($quizCourse));
    $em->persist((new Comment())->setContent('Commentaire conservé')->setUser($user)->setCourse($quizCourse));
    $em->persist(new QuizAttempt($user, $quizCourse, $quizCourse->getQuiz(), ['version' => 1, 'title' => 'Tentative conservée', 'questions' => []]));
    $em->persist((new ExerciceAttempt())->setUser($user)->setExercice($exercise)->setScore(1)->setTotal(2)->setPercentage(50));
    $em->flush();
    $em->clear();
    // Reconstruct the pre-migration schema, with all fixtures already present.
    $connection->executeStatement('ALTER TABLE courses DROP is_free');
    $snapshot = static function () use ($connection): array {
        $rows = [];
        foreach ($connection->createSchemaManager()->listTableNames() as $table) {
            $rows[$table] = $connection->fetchAllAssociative('SELECT * FROM '.$connection->quoteIdentifier($table).' ORDER BY id');
        }

        return $rows;
    };
    $before = $snapshot();
    $factory = new DbalMigrationFactory($connection, new NullLogger());
    $migration = $factory->createVersion('DoctrineMigrations\\Version20261001120000');
    $migration->up(new Schema());
    $sql = array_map(static fn ($query) => $query->getStatement(), $migration->getSql());
    $check(['ALTER TABLE courses ADD is_free TINYINT DEFAULT 0 NOT NULL'] === $sql, 'Seul l’ajout de la colonne est autorisé.');
    $connection->executeStatement($sql[0]);
    $after = $snapshot();
    foreach ($after['courses'] as &$row) {
        $check(0 === (int) $row['is_free'], 'Chaque cours préexistant doit rester réservé.');
        unset($row['is_free']);
    }
    unset($row);
    $check($before === $after, 'Toutes les lignes et associations doivent être conservées à l’identique.');
    $column = $connection->fetchAssociative("SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'is_free'");
    $check('NO' === $column['IS_NULLABLE'] && '0' === (string) $column['COLUMN_DEFAULT'], 'Colonne NOT NULL et DEFAULT 0 requis.');
    $connection->insert('courses', ['name' => 'Défaut SQL', 'slug' => 'defaut-sql', 'content_type' => 'twig', 'position' => 99, 'section_id' => $quizCourse->getSection()->getId()]);
    $insertedId = $connection->lastInsertId();
    $check(0 === (int) $connection->fetchOne('SELECT is_free FROM courses WHERE id = ?', [$insertedId]), 'Une insertion SQL sans valeur doit rester réservée.');
    $connection->delete('courses', ['id' => $insertedId]);
    $migration = $factory->createVersion('DoctrineMigrations\\Version20261001120000');
    $migration->down(new Schema());
    foreach ($migration->getSql() as $query) {
        $connection->executeStatement($query->getStatement());
    }
    $check($before === $snapshot(), 'Le retour arrière doit conserver toutes les données antérieures.');
    echo "OK : migration up/down, six types de cours préexistants, défaut SQL, non-nullabilité et toutes les données pédagogiques conservées.\n";
} finally {
    $connection?->close();
    if ($created && 1 === preg_match('/^orthogram_courses_free_[a-f0-9]{16}_test$/D', $database)) {
        $server->executeStatement('DROP DATABASE '.$server->quoteIdentifier($database));
        echo "Base jetable supprimée : $database\n";
    }
    $server->close();
}
