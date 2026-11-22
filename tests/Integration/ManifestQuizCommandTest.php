<?php

namespace App\Tests\Integration;

use App\Entity\Courses;
use App\Entity\Quiz;
use App\Entity\QuizAttempt;
use App\Services\EditorialQuizManifest;
use App\Services\ManifestQuizDestination;
use App\Services\ManifestQuizImporter;
use App\Services\ModuleOneReserveImporter;
use App\Services\ModuleOneReserveManifest;
use App\Services\ModuleThreeQuizManifest;
use App\Services\ModuleTwoQuizManifest;
use App\Services\QuizAttemptService;
use App\Services\QuizCommandDestination;
use App\Services\QuizConfigurationValidator;
use App\Services\QuizCorrectionService;
use App\Services\QuizEditorialService;
use App\Services\QuizQuestionSelector;
use App\Services\QuizReleaseSnapshot;
use App\Tests\Support\DictationTestDatabase;
use App\Tests\Support\ModuleOneReserveFixture;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Validator\Validation;

final class ManifestQuizCommandTest extends TestCase
{
    private const MINI = 'tests/Fixtures/Quiz/essai.json';
    private const MODULE = 'resources/quiz/module4/module4_quiz_manifest.json';
    private DictationTestDatabase $database;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->database = new DictationTestDatabase(recipe: 'editorial');
        $this->database->install();
        $this->em = $this->database->manager();
    }

    protected function tearDown(): void
    {
        $this->database->close();
    }

    /** @param list<string> $flags
     * @return array<string, mixed> */
    private function runManifest(string $file, array $flags = [], int $status = 0): array
    {
        $db = $this->em->getConnection();
        $process = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', 'bin/console', 'app:quiz:manifest', $file,
            '--env=test', '--expect-env=test', '--expect-database='.$this->database->name, '--expect-host='.$db->getParams()['host'],
            '--expect-port='.$db->getParams()['port'], '--expect-server='.$db->fetchOne('SELECT @@hostname'), ...$flags],
            dirname(__DIR__, 2), ['DATABASE_URL' => $this->database->kernelUrl(), 'SYMFONY_DOTENV_VARS' => false]);
        self::assertSame($status, $process->run(), $process->getOutput().$process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function rows(): array
    {
        return array_map(static fn ($table) => $table['rows'], (new QuizReleaseSnapshot())->capture($this->em->getConnection()));
    }

    public function testFixedImportPreservesPreviousQuizAndHistoryAndSupportsSeparateRenewal(): void
    {
        $mini = $this->runManifest(self::MINI);
        self::assertSame([1, 1, 2], [$mini['add_quizzes'], $mini['add_questions'], $mini['add_answers']]);
        $this->runManifest(self::MINI, ['--apply', '--expect-plan='.$mini['plan_hash']]);
        $previous = $this->runManifest(self::MINI);
        self::assertTrue($this->runManifest(self::MINI, ['--apply', '--expect-plan='.$previous['plan_hash']])['noop']);
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $configuration = new QuizConfigurationValidator($validator);
        $editorial = new QuizEditorialService($this->em, $configuration);
        $resources = dirname(__DIR__, 2).'/resources/quiz';
        $one = new ModuleOneReserveManifest($resources.'/module1_reserve.json');
        $fixture = ModuleOneReserveFixture::create($this->em, $one);
        $reserves = new ModuleOneReserveImporter($this->em, $editorial, $configuration, $one);
        $reserves->apply('import', $reserves->plan()['plan_hash']);
        $destination = new ManifestQuizDestination(new QuizCommandDestination('test'));
        foreach ([new ModuleTwoQuizManifest($resources.'/module2/module2_quiz_manifest.json'), new ModuleThreeQuizManifest($resources.'/module3/module3_quiz_manifest.json')] as $oldManifest) {
            $importer = new ManifestQuizImporter($this->em, $oldManifest, $configuration, $editorial, $destination);
            $oldPlan = $importer->plan('import', $previous['destination']);
            self::assertSame([], $oldPlan['errors']);
            $importer->apply('import', $oldPlan['plan_hash'], $previous['destination']);
        }
        $user = $fixture['user'];
        $course = $fixture['courses']['module1_test1'];
        $selector = new QuizQuestionSelector($configuration);
        $attempts = new QuizAttemptService($this->em, new QuizCorrectionService($validator), $selector);
        $start = $attempts->mutate($user, $course, 'start', []);
        $attempt = $this->em->find(QuizAttempt::class, $start['attemptId']);
        foreach ($attempt->getSnapshot()['questions'] as $question) {
            $attempts->mutate($user, $course, 'answer', ['attemptId' => $attempt->getId(), 'questionId' => $question['id'], 'selectedIds' => [$question['answers'][0]['id']]]);
        }
        $history = $attempts->mutate($user, $course, 'finish', ['attemptId' => $attempt->getId()]);
        $resume = $attempts->mutate($user, $course, 'restart', ['attemptId' => $attempt->getId()]);
        $before = $this->rows();

        $plan = $this->runManifest(self::MODULE);
        self::assertSame($before, $this->rows());
        self::assertSame([3, 90, 180], [$plan['add_quizzes'], $plan['add_questions'], $plan['add_answers']]);
        self::assertSame(['module4_docx_001', 'module4_docx_014', 'module4_docx_041', 'module4_docx_051', 'module4_docx_069', 'module4_docx_095'], $plan['held_sources']);
        $apply = ['--apply', '--expect-plan='.$plan['plan_hash']];
        $this->runManifest(self::MODULE, $apply);
        self::assertStringContainsString('périmé', $this->runManifest(self::MODULE, $apply, 1)['error']);
        $fixed = $this->runManifest(self::MODULE);
        self::assertSame([0, 0, 0, 90], [$fixed['add_quizzes'], $fixed['add_questions'], $fixed['add_answers'], $fixed['existing_copies']]);
        $after = $this->rows();
        $added = ['quiz' => 3, 'quiz_question' => 90, 'quiz_answer' => 180, 'quiz_manifest_identity' => 3, 'quiz_reserve_import' => 90];
        foreach ($before as $table => $rows) {
            self::assertCount(count($rows) + ($added[$table] ?? 0), $after[$table], $table);
            foreach ($rows as $row) {
                self::assertContains($row, $after[$table], $table);
            }
        }
        self::assertTrue($this->runManifest(self::MODULE, ['--apply', '--expect-plan='.$fixed['plan_hash']])['noop']);
        self::assertSame($after, $this->rows());
        self::assertEquals($resume, $attempts->state($user, $course));
        self::assertEquals($history, $attempts->state($user, $course, $attempt->getId()));

        $specs = json_decode(file_get_contents(dirname(__DIR__, 2).'/'.self::MODULE), true, flags: JSON_THROW_ON_ERROR)['quizzes'];
        $priorAttempts = [];
        foreach ($specs as $spec) {
            $registered = $fixed['quizzes'][$spec['quiz_key']];
            $quiz = $this->em->find(Quiz::class, $registered['id']);
            self::assertFalse($quiz->isRenewalEnabled());
            self::assertNull($quiz->getErrorQuestionQuota());
            self::assertNotContains($quiz->getId(), array_column($after['courses'], 'quiz_id'));
            $initial = $quiz->getInitialQuestions();
            self::assertSame(array_map(static fn ($key) => $registered['question_ids'][$key], $spec['initial_source_keys']), array_map(static fn ($q) => $q->getId(), $initial));
            self::assertSame([], array_intersect(array_keys($registered['question_ids']), $plan['held_sources']));
            $lesson = (new Courses())->setName($spec['title'])->setSlug($spec['quiz_key'])->setSection($course->getSection())->setContentType($course->getContentType())->setQuiz($quiz);
            $this->em->persist($lesson);
            $this->em->flush();
            $started = $attempts->mutate($user, $lesson, 'start', []);
            $priorAttempts[$spec['quiz_key']] = $started['attemptId'];
            $firstPass = $this->em->find(QuizAttempt::class, $started['attemptId'])->getSnapshot()['questions'];
            self::assertSame(array_map(static fn ($q) => $q->getId(), $initial), array_column($firstPass, 'id'));
            $question = $firstPass[0];
            $answered = $attempts->mutate($user, $lesson, 'answer', ['attemptId' => $started['attemptId'], 'questionId' => $question['id'], 'selectedIds' => [$question['answers'][0]['id']]]);
            self::assertEquals($answered, $attempts->state($user, $lesson));
        }
        $historyRows = $this->rows()['quiz_attempt'];
        $enable = $this->runManifest(self::MODULE, ['enable']);
        self::assertCount(3, $enable['renewal_changes']);
        $this->runManifest(self::MODULE, ['enable', '--apply', '--expect-plan='.$enable['plan_hash']]);
        $this->em->clear();
        foreach ($fixed['quizzes'] as $key => $registered) {
            $quiz = $this->em->find(Quiz::class, $registered['id']);
            $initial = $quiz->getInitialQuestions();
            $ids = array_map(static fn ($q) => $q->getId(), $initial);
            $next = $selector->select($quiz, $this->em->find(QuizAttempt::class, $priorAttempts[$key]));
            self::assertSame(array_map(static fn ($q) => $q->getTheme(), $initial), array_map(static fn ($q) => $q->getTheme(), $next));
            self::assertSame([], array_intersect($ids, array_map(static fn ($q) => $q->getId(), $next)));
        }
        self::assertSame($historyRows, $this->rows()['quiz_attempt']);
    }

    public function testCollisionsAndBothDirectionsOfContentDriftAreReportedWithoutWrites(): void
    {
        $db = $this->em->getConnection();
        $plan = $this->runManifest(self::MINI);
        $this->runManifest(self::MINI, ['--apply', '--expect-plan='.$plan['plan_hash']]);
        $fixed = $this->runManifest(self::MINI);
        $qid = $fixed['quizzes']['grand_essai_final']['question_ids']['grand_essai_docx_007'];
        $module = json_decode(file_get_contents(dirname(__DIR__, 2).'/'.self::MODULE), true, flags: JSON_THROW_ON_ERROR);
        $db->update('quiz', ['title' => $module['quizzes'][0]['title']], ['id' => $fixed['quizzes']['grand_essai_final']['id']]);
        $db->update('quiz_question', ['text' => $module['sources'][1]['candidate']['text']], ['id' => $qid]);
        $before = $this->rows();
        $blocked = $this->runManifest(self::MODULE, status: 1);
        self::assertContains('title_collision', array_column($blocked['differences'], 'field'));
        self::assertContains('content_collision', array_column($blocked['differences'], 'field'));
        $this->runManifest(self::MODULE, ['--apply', '--expect-plan='.$blocked['plan_hash']], 1);
        self::assertSame($before, $this->rows());
        $edited = $this->runManifest(self::MINI, status: 1);
        $diff = array_values(array_filter($edited['differences'], static fn ($d) => 'text' === $d['field']))[0];
        self::assertSame(['grand_essai_docx_007', 'Les pages sont prêtes.', $module['sources'][1]['candidate']['text']], [$diff['source_key'], $diff['expected'], $diff['actual']]);
        $this->runManifest(self::MINI, ['--apply', '--expect-plan='.$edited['plan_hash']], 1);
        self::assertSame($before, $this->rows());

        $revised = json_decode(file_get_contents(dirname(__DIR__, 2).'/'.self::MINI), true, flags: JSON_THROW_ON_ERROR);
        $db->update('quiz', ['title' => $revised['quizzes'][0]['title']], ['id' => $fixed['quizzes']['grand_essai_final']['id']]);
        $db->update('quiz_question', ['text' => $revised['sources'][0]['candidate']['text']], ['id' => $qid]);
        $before = $this->rows();
        $candidate = &$revised['sources'][0]['candidate'];
        $candidate['explanation'] = 'Explication révisée.';
        $payload = array_intersect_key($candidate, array_flip(['title', 'text', 'explanation', 'theme', 'answers', 'multiple', 'contains_error']));
        $revised['copies'][0]['payload_sha256'] = EditorialQuizManifest::hash($payload + ['reserve' => false, 'position' => 0]);
        $file = sys_get_temp_dir().'/orthogram-'.bin2hex(random_bytes(8)).'.json';
        try {
            file_put_contents($file, json_encode($revised, JSON_THROW_ON_ERROR));
            $changed = $this->runManifest($file, status: 1);
            self::assertSame(['manifest_hash', 'payload_hash', 'explanation'], array_column($changed['differences'], 'field'));
            $diff = array_values(array_filter($changed['differences'], static fn ($d) => 'explanation' === $d['field']))[0];
            self::assertSame(['grand_essai_final', 'grand_essai_docx_007', 'Explication révisée.', 'Prêtes s’accorde avec pages.'], [$diff['quiz_key'], $diff['source_key'], $diff['expected'], $diff['actual']]);
            $this->runManifest($file, ['--apply', '--expect-plan='.$changed['plan_hash']], 1);
            self::assertSame($before, $this->rows());
        } finally {
            unlink($file);
        }
    }
}
