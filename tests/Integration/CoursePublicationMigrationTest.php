<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Courses;
use App\Entity\Exercice;
use App\Entity\ExerciceAttempt;
use App\Entity\Lesson;
use App\Enum\LessonStatus;
use App\Tests\Support\DictationTestDatabase;
use App\Tests\Support\QuizPlayerFactory;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Version\DbalMigrationFactory;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CoursePublicationMigrationTest extends TestCase
{
    public function testExistingCoursesRemainPublishedAndAllOtherDataIsUnchanged(): void
    {
        $database = new DictationTestDatabase();
        try {
            $database->install();
            $em = $database->manager();
            [$user, $course] = QuizPlayerFactory::create($em);
            $exercise = (new Exercice())->setTitle('Historique')->setInstruction('Test');
            $course->setExercice($exercise)->setPosition(8)->setDurationMinutes(9)->setPartialFileName('historique.html.twig');
            $lesson = (new Lesson())->setName('Validation historique')->setUser($user)->setCourse($course)->setStatus(LessonStatus::DONE);
            $attempt = (new ExerciceAttempt())->setUser($user)->setExercice($exercise)->setScore(1)->setTotal(1)->setPercentage(100);
            $user->setLastVisitedCourse($course);
            foreach ([$exercise, $lesson, $attempt] as $entity) {
                $em->persist($entity);
            }
            $em->flush();
            $connection = $em->getConnection();
            self::assertSame($database->name, $connection->fetchOne('SELECT DATABASE()'));
            require_once dirname(__DIR__, 2).'/migrations/Version20261116120000.php';
            $factory = new DbalMigrationFactory($connection, new NullLogger());
            $apply = static function (bool $up) use ($factory, $connection): void {
                $migration = $factory->createVersion(\DoctrineMigrations\Version20261116120000::class);
                $up ? $migration->up(new Schema()) : $migration->down(new Schema());
                foreach ($migration->getSql() as $sql) {
                    $connection->executeStatement($sql->getStatement());
                }
            };
            $apply(false);
            $snapshot = static function () use ($connection): array {
                $rows = [];
                foreach ($connection->createSchemaManager()->listTableNames() as $table) {
                    $rows[$table] = $connection->fetchAllAssociative('SELECT * FROM '.$connection->quoteIdentifier($table));
                }

                return $rows;
            };
            $before = $snapshot();
            $apply(true);
            $after = $snapshot();
            foreach ($after['courses'] as &$row) {
                self::assertSame(1, (int) $row['is_published']);
                unset($row['is_published']);
            }
            unset($row);
            self::assertSame($before, $after);
            self::assertSame([], (new SchemaValidator($em))->validateMapping());
            self::assertSame([], (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata()));
            $column = $connection->createSchemaManager()->introspectTable('courses')->getColumn('is_published');
            self::assertTrue($column->getNotnull());
            self::assertSame('1', (string) $column->getDefault());
            $em->clear();
            self::assertTrue($em->find(Courses::class, $course->getId())->isPublished());
            $apply(false);
            self::assertSame($before, $snapshot());
        } finally {
            $database->close();
        }
    }
}
