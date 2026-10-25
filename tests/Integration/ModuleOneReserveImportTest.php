<?php

namespace App\Tests\Integration;

use App\Entity\QuizAttempt;
use App\Entity\QuizReserveImport;
use App\Services\ModuleOneReserveImporter;
use App\Services\ModuleOneReserveManifest;
use App\Services\QuizAttemptService;
use App\Services\QuizConfigurationValidator;
use App\Services\QuizCorrectionService;
use App\Services\QuizEditorialService;
use App\Services\QuizQuestionSelector;
use App\Tests\Support\DictationTestDatabase;
use App\Tests\Support\ModuleOneReserveFixture;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class ModuleOneReserveImportTest extends TestCase
{
    private DictationTestDatabase $database;
    private EntityManagerInterface $em;
    private ModuleOneReserveImporter $importer;
    private QuizAttemptService $attempts;
    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        $this->database = new DictationTestDatabase();
        $this->database->install();
        $this->em = $this->database->manager();
        $manifest = new ModuleOneReserveManifest(dirname(__DIR__, 2).'/resources/quiz/module1_reserve.json');
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $configuration = new QuizConfigurationValidator($validator);
        $this->importer = new ModuleOneReserveImporter($this->em, new QuizEditorialService($this->em, $configuration), $configuration, $manifest);
        $this->attempts = new QuizAttemptService($this->em, new QuizCorrectionService($validator), new QuizQuestionSelector($configuration));
        $this->fixture = ModuleOneReserveFixture::create($this->em, $manifest);
    }

    protected function tearDown(): void
    {
        $this->database->close();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function rows(): array
    {
        $result = [];
        foreach (['quiz', 'quiz_question', 'quiz_answer', 'courses', 'quiz_attempt', 'lesson', 'quiz_reserve_import'] as $table) {
            $result[$table] = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY '.('quiz_reserve_import' === $table ? 'quiz_id, source_key' : 'id'));
        }

        return $result;
    }

    public function testImportPreservesAllExistingRowsAndIsIdempotentEvenWithOldPreview(): void
    {
        $user = $this->fixture['user'];
        $course = $this->fixture['courses']['module1_test1'];
        $state = $this->attempts->mutate($user, $course, 'start', []);
        $snapshot = $this->em->find(QuizAttempt::class, $state['attemptId'])->getSnapshot();
        $q = $snapshot['questions'][0];
        $state = $this->attempts->mutate($user, $course, 'answer', ['attemptId' => $state['attemptId'], 'questionId' => $q['id'], 'selectedIds' => array_column(array_filter($q['answers'], static fn ($a) => $a['correct']), 'id')]);
        $before = $this->rows();
        $plan = $this->importer->plan();
        self::assertSame([], $plan['errors']);
        self::assertSame([], $plan['initial_differences']);
        self::assertSame($before, $this->rows());
        self::assertSame(['questions' => 208, 'answers' => 528], $plan['expected_totals']);
        self::assertGreaterThan(1, $plan['quizzes']['fundamentals_1']['id']);
        self::assertSame(94, $this->importer->apply('import', $plan['plan_hash'])['questions_added']);
        $after = $this->rows();
        foreach ($before as $table => $rows) {
            foreach ($rows as $row) {
                self::assertContains($row, $after[$table], $table);
            }
        }
        self::assertCount(208, $after['quiz_question']);
        self::assertCount(528, $after['quiz_answer']);
        self::assertCount(94, $after['quiz_reserve_import']);
        self::assertFalse((bool) array_filter($after['quiz'], static fn ($q) => $q['renewal_enabled']));
        self::assertEquals($state, $this->attempts->state($user, $course));
        self::assertSame(0, $this->importer->apply('import', $plan['plan_hash'])['questions_added']);
        self::assertSame($after, $this->rows());
        $enable = $this->importer->plan('enable');
        $this->importer->apply('enable', $enable['plan_hash']);
        self::assertEquals($state, $this->attempts->state($user, $course));
        $disable = $this->importer->plan('disable');
        $this->importer->apply('disable', $disable['plan_hash']);
        self::assertSame($after, $this->rows());
    }

    public function testRenewalKeepsFirstPassageQuotasAndMinimumReuseWithoutCoveringWholeBank(): void
    {
        $plan = $this->importer->plan();
        $this->importer->apply('import', $plan['plan_hash']);
        $plan = $this->importer->plan('enable');
        $this->importer->apply('enable', $plan['plan_hash']);
        foreach (['module1_test1' => 2, 'module1_test2' => 3, 'module1_test3' => 0, 'module1_test4' => 1] as $key => $minimum) {
            $course = $this->fixture['courses'][$key];
            $user = $this->fixture['user'];
            $first = $this->attempts->mutate($user, $course, 'start', []);
            $attempt = $this->em->find(QuizAttempt::class, $first['attemptId']);
            $initial = $attempt->getSnapshot()['questions'];
            self::assertSame(array_map(static fn ($q) => $q->getId(), $course->getQuiz()->getInitialQuestions()), array_column($initial, 'id'));
            $this->finish($user, $course, $attempt);
            $second = $this->attempts->mutate($user, $course, 'restart', ['attemptId' => $attempt->getId()]);
            $next = $this->em->find(QuizAttempt::class, $second['attemptId']);
            $renewed = $next->getSnapshot()['questions'];
            self::assertSame(count($initial), count($renewed));
            self::assertSame(array_column($initial, 'theme'), array_column($renewed, 'theme'));
            self::assertCount($minimum, array_intersect(array_column($initial, 'id'), array_column($renewed, 'id')));
            $this->finish($user, $course, $next);
            $third = $this->attempts->mutate($user, $course, 'restart', ['attemptId' => $next->getId()]);
            $thirdQuestions = $this->em->find(QuizAttempt::class, $third['attemptId'])->getSnapshot()['questions'];
            self::assertEqualsCanonicalizing(array_column($initial, 'id'), array_column($thirdQuestions, 'id'));
            self::assertLessThan($course->getQuiz()->getQuestions()->count(), count(array_unique([...array_column($initial, 'id'), ...array_column($renewed, 'id'), ...array_column($thirdQuestions, 'id')])));
        }
    }

    private function finish(\App\Entity\User $user, \App\Entity\Courses $course, QuizAttempt $attempt): void
    {
        foreach ($attempt->getSnapshot()['questions'] as $q) {
            $this->attempts->mutate($user, $course, 'answer', ['attemptId' => $attempt->getId(), 'questionId' => $q['id'], 'selectedIds' => array_column(array_filter($q['answers'], static fn ($a) => $a['correct']), 'id')]);
        }
        $this->attempts->mutate($user, $course, 'finish', ['attemptId' => $attempt->getId()]);
    }

    /** @return iterable<string, array{string}> */
    public static function corruptions(): iterable
    {
        foreach (['partial', 'content', 'deleted', 'moved', 'collision'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('corruptions')]
    public function testRefusesPartialDivergentOrCollidingImportsWithoutWrites(string $mode): void
    {
        $plan = $this->importer->plan();
        $this->importer->apply('import', $plan['plan_hash']);
        $db = $this->em->getConnection();
        $row = $db->fetchAssociative('SELECT * FROM quiz_reserve_import ORDER BY quiz_id, source_key LIMIT 1');
        if ('partial' === $mode) {
            $db->delete('quiz_reserve_import', ['quiz_id' => $row['quiz_id'], 'source_key' => $row['source_key']]);
        }
        if ('content' === $mode) {
            $db->update('quiz_question', ['explanation' => 'Divergence'], ['id' => $row['question_id']]);
        }
        if ('deleted' === $mode) {
            $db->delete('quiz_question', ['id' => $row['question_id']]);
        }
        if ('moved' === $mode) {
            $db->update('quiz_question', ['quiz_id' => $this->fixture['quizzes']['fundamentals_1']->getId()], ['id' => $row['question_id']]);
        }
        if ('collision' === $mode) {
            $question = $this->fixture['quizzes']['module1_test1']->getInitialQuestions()[0];
            $db->update('quiz_question', ['text' => $db->fetchOne('SELECT text FROM quiz_question WHERE id = ?', [$row['question_id']])], ['id' => $question->getId()]);
        }
        $before = $this->rows();
        try {
            $this->importer->apply('import', $plan['plan_hash']);
            self::fail('Import doit être refusé.');
        } catch (\DomainException $error) {
            self::assertNotSame('', $error->getMessage());
        }
        self::assertSame($before, $this->rows());
    }

    public function testChangedPreviewAndFlushFailureRollbackEverything(): void
    {
        $plan = $this->importer->plan();
        $id = $this->fixture['quizzes']['module1_test1']->getInitialQuestions()[0]->getId();
        $this->em->getConnection()->update('quiz_question', ['explanation' => 'Édition locale à conserver'], ['id' => $id]);
        $before = $this->rows();
        try {
            $this->importer->apply('import', $plan['plan_hash']);
            self::fail('Prévisualisation périmée.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('prévisualisation', $error->getMessage());
        }
        self::assertSame($before, $this->rows());
        $plan = $this->importer->plan();
        self::assertNotEmpty($plan['initial_differences']);
        $this->em->getEventManager()->addEventListener(['postPersist'], new class {
            public function postPersist(\Doctrine\ORM\Event\PostPersistEventArgs $event): void
            {
                if ($event->getObject() instanceof QuizReserveImport) {
                    throw new \RuntimeException('Échec injecté après insertion réelle');
                }
            }
        });
        try {
            $this->importer->apply('import', $plan['plan_hash']);
            self::fail('Échec attendu.');
        } catch (\RuntimeException $error) {
            self::assertSame('Échec injecté après insertion réelle', $error->getMessage());
        }
        self::assertSame($before, $this->rows());
    }
}
