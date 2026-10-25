<?php

namespace App\Tests\Integration;

use App\Command\QuizReserveCommand;
use App\Entity\Courses;
use App\Entity\Program;
use App\Entity\Quiz;
use App\Entity\Sections;
use App\Services\ModuleOneReserveImporter;
use App\Services\ModuleOneReserveManifest;
use App\Services\QuizCommandDestination;
use App\Services\QuizConfigurationValidator;
use App\Services\QuizEditorialService;
use App\Services\QuizReleaseSnapshot;
use App\Tests\Support\DictationTestDatabase;
use App\Tests\Support\ModuleOneReserveFixture;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\Validation;

final class ModuleOneReserveResolutionTest extends TestCase
{
    private const MANIFEST_HASH = 'a7ea9d939aa93971fdf29e3661f400f5043a77ca51fd87c462ab841e4cef72ba';
    private DictationTestDatabase $database;
    private EntityManagerInterface $em;
    private ModuleOneReserveImporter $importer;
    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        $this->database = new DictationTestDatabase();
        $this->database->install();
        $this->em = $this->database->manager();
        $manifest = new ModuleOneReserveManifest(dirname(__DIR__, 2).'/resources/quiz/module1_reserve.json');
        self::assertSame(self::MANIFEST_HASH, $manifest->hash);
        $this->fixture = ModuleOneReserveFixture::create($this->em, $manifest);
        $validator = new QuizConfigurationValidator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
        $this->importer = new ModuleOneReserveImporter($this->em, new QuizEditorialService($this->em, $validator), $validator, $manifest);
    }

    protected function tearDown(): void
    {
        $this->database->close();
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return (new QuizReleaseSnapshot())->capture($this->em->getConnection());
    }

    /** @return iterable<string, array{string}> */
    public static function acceptedTitles(): iterable
    {
        yield 'manifeste' => ['Test général : réviser les fondamentaux'];
        yield 'bêta confirmé' => ['Test : réviser les fondamentaux'];
    }

    #[DataProvider('acceptedTitles')]
    public function testAcceptedTitlesResolveWithoutChangingAnyData(string $title): void
    {
        $quiz = $this->fixture['quizzes']['fundamentals_4'];
        $quiz->setTitle($title);
        $this->em->flush();
        $before = $this->snapshot();
        $resolved = $this->importer->resolve();
        self::assertSame($quiz, $resolved['fundamentals_4']);
        self::assertCount(8, array_unique(array_map(static fn ($q) => $q->getId(), $resolved)));
        self::assertGreaterThan(1, $resolved['fundamentals_1']->getId());
        // Exercise the same read-only production preview as the reported beta failure,
        // with a guarded disposable local database, never a production connection.
        $db = $this->em->getConnection();
        $command = new CommandTester(new QuizReserveCommand($this->importer, $this->em, new QuizCommandDestination('prod')));
        self::assertSame(0, $command->execute(['--expect-env' => 'prod', '--expect-database' => $this->database->name,
            '--expect-host' => $db->getParams()['host'], '--expect-port' => (string) ($db->getParams()['port'] ?? 3306), '--expect-server' => $db->fetchOne('SELECT @@hostname')]), $command->getDisplay());
        $plan = json_decode($command->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($plan['write_requested']);
        self::assertSame([], $plan['errors']);
        self::assertSame([], $plan['initial_differences']);
        self::assertSame($title, $plan['quizzes']['fundamentals_4']['title']);
        self::assertSame(self::MANIFEST_HASH, $plan['manifest_sha256']);
        self::assertSame($before, $this->snapshot());
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedResolutions(): iterable
    {
        foreach (['unknown_title', 'case_variant', 'trailing_space', 'course_slug', 'section_slug', 'program_slug', 'multiple_courses', 'both_titles_match', 'duplicate_same_title'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('rejectedResolutions')]
    public function testInvalidOrAmbiguousFundamentalsResolutionIsRefused(string $case): void
    {
        $quiz = $this->fixture['quizzes']['fundamentals_4'];
        $course = $this->fixture['courses']['fundamentals_4'];
        if ('unknown_title' === $case) {
            $quiz->setTitle('Test de révision des fondamentaux');
        }
        if ('case_variant' === $case) {
            $quiz->setTitle('Test : Réviser les fondamentaux');
        }
        if ('trailing_space' === $case) {
            $quiz->setTitle('Test : réviser les fondamentaux ');
        }
        if ('course_slug' === $case) {
            $course->setSlug('autre-cours');
        }
        if (in_array($case, ['section_slug', 'program_slug'], true)) {
            $program = $course->getSection()->getProgram();
            if ('program_slug' === $case) {
                $program = (new Program())->setName('Autre formation')->setSlug('autre-formation')->setPrice(0)->setDescription('Fixture isolée');
                $this->em->persist($program);
            }
            $section = (new Sections())->setName('Fixture isolée')->setSlug('section_slug' === $case ? 'autre-section' : 'les-fondamentaux')->setProgram($program);
            $this->em->persist($section);
            $course->setSection($section);
        }
        if (in_array($case, ['multiple_courses', 'both_titles_match', 'duplicate_same_title'], true)) {
            $otherQuiz = $quiz;
            if ('multiple_courses' !== $case) {
                $otherQuiz = (new Quiz())->setTitle('both_titles_match' === $case ? 'Test : réviser les fondamentaux' : $quiz->getTitle());
                $this->em->persist($otherQuiz);
            }
            $other = (new Courses())->setName('Rattachement de recette')->setSlug('multiple_courses' === $case ? 'autre-rattachement' : $course->getSlug())->setSection($course->getSection())->setQuiz($otherQuiz);
            $this->em->persist($other);
        }
        $this->em->flush();
        $before = $this->snapshot();
        try {
            $this->importer->resolve();
            self::fail('Résolution invalide acceptée.');
        } catch (\DomainException $error) {
            self::assertNotSame('', $error->getMessage());
        }
        self::assertSame($before, $this->snapshot());
    }

    /** @return iterable<string, array{string}> */
    public static function otherQuizzes(): iterable
    {
        foreach (['fundamentals_1', 'fundamentals_2', 'fundamentals_3', 'module1_test1', 'module1_test2', 'module1_test3', 'module1_test4'] as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('otherQuizzes')]
    public function testCompatibilityDoesNotApplyToAnyOtherQuiz(string $key): void
    {
        $this->fixture['quizzes'][$key]->setTitle('Test : réviser les fondamentaux');
        $this->em->flush();
        $before = $this->snapshot();
        try {
            $this->importer->resolve();
            self::fail('Titre accepté hors fundamentals_4.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('Résolution ambiguë ou impossible : '.$key, $error->getMessage());
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testPreviouslyImportedReservesKeepTheirOriginalFingerprintsAndAreRecognized(): void
    {
        // Seed the previously imported state only in this disposable database.
        $plan = $this->importer->plan();
        $this->importer->apply('import', $plan['plan_hash']);
        $db = $this->em->getConnection();
        $traces = $db->fetchAllAssociative('SELECT * FROM quiz_reserve_import ORDER BY quiz_id, source_key');
        self::assertCount(94, $traces);
        self::assertSame([self::MANIFEST_HASH], array_values(array_unique(array_column($traces, 'manifest_hash'))));
        $this->fixture['quizzes']['fundamentals_4']->setTitle('Test : réviser les fondamentaux');
        $this->em->flush();
        $before = $this->snapshot();
        $after = $this->importer->plan();
        self::assertSame([], $after['errors']);
        self::assertSame(94, $after['existing_copies']);
        self::assertSame(0, $after['add_questions']);
        self::assertSame(0, $after['add_answers']);
        self::assertSame(self::MANIFEST_HASH, $after['manifest_sha256']);
        self::assertSame($before, $this->snapshot());
        self::assertSame($traces, $db->fetchAllAssociative('SELECT * FROM quiz_reserve_import ORDER BY quiz_id, source_key'));
        $db->update('quiz_reserve_import', ['manifest_hash' => str_repeat('0', 64)], ['quiz_id' => $traces[0]['quiz_id'], 'source_key' => $traces[0]['source_key']]);
        self::assertStringContainsString('provenance ou contenu divergent', implode("\n", $this->importer->plan()['errors']));
    }
}
