<?php

namespace App\Tests\Controller\Course;

use App\Entity\Courses;
use App\Entity\Exercice;
use App\Entity\ExerciceAttempt;
use App\Entity\Lesson;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CourseContentType;
use App\Enum\LessonStatus;
use App\Enum\SubscriptionStatus;
use App\Tests\Support\DictationFactory;
use App\Tests\Support\QuizPlayerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExerciseCompletionTest extends WebTestCase
{
    private const URL = '/courses/quiz-formation/quiz-section/quiz-cours';
    private KernelBrowser $client;
    private string $database;
    /** @var array<string, array{?string, ?string}> */
    private array $previous = [];
    private int $courseId;
    private int $exerciseId;
    private int $userId;
    private string $token;

    protected function setUp(): void
    {
        $this->database = str_replace('\\', '/', dirname(__DIR__, 3).'/var/exercise-'.bin2hex(random_bytes(8)).'.sqlite');
        foreach (['DATABASE_URL' => 'sqlite:///'.$this->database, 'DICTATION_LEARNER_ENABLED' => '1'] as $key => $value) {
            $this->previous[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $this->client = self::createClient();
        $connection = $this->em()->getConnection();
        self::assertSame('pdo_sqlite', $connection->getParams()['driver']);
        self::assertSame($this->database, $connection->getParams()['path']);
        self::assertSame($this->database, str_replace('\\', '/', $connection->fetchAllAssociative('PRAGMA database_list')[0]['file']));
        (new SchemaTool($this->em()))->createSchema($this->em()->getMetadataFactory()->getAllMetadata());
        [$user, $course] = QuizPlayerFactory::create($this->em());
        $exercise = (new Exercice())->setTitle('Les verbes')->setInstruction('Sélectionnez le verbe.')->setSentences([
            ['id' => 's1', 'words' => [['id' => 'yes', 'text' => 'Chante', 'isAnswer' => true], ['id' => 'no', 'text' => 'la']]],
        ]);
        $course->setContentType(CourseContentType::Exercise)->setExercice($exercise);
        $this->em()->persist($exercise);
        $this->em()->flush();
        $this->courseId = $course->getId();
        $this->exerciseId = $exercise->getId();
        $this->userId = $user->getId();
        $this->client->loginUser($user);
        $page = $this->client->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        $this->token = $page->filter('[data-controller="click-words"]')->attr('data-click-words-token-value');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->database) && is_file($this->database)) {
            unlink($this->database);
        }
        foreach ($this->previous as $key => [$env, $server]) {
            if (null === $env) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
            if (null === $server) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
        }
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param list<string> $selected
     * @return array<string, mixed>
     */
    private function submit(array $selected, ?int $courseId = null, bool $standalone = false, ?string $token = null): array
    {
        $payload = ['selected' => $selected];
        if (!$standalone) {
            $payload['courseId'] = $courseId ?? $this->courseId;
        }
        $this->client->jsonRequest('POST', '/exercise/'.$this->exerciseId.'/submit', $payload,
            ['HTTP_X_CSRF_TOKEN' => $token ?? $this->token]);

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testSuccessAfterFailureIsPermanentAndRefreshesBothSidebars(): void
    {
        self::assertSelectorNotExists('#completion-button form');
        self::assertSelectorExists('#completion-button[hidden]');
        self::assertSelectorExists('[data-click-words-course-id-value="'.$this->courseId.'"]');
        self::assertFalse($this->submit([])['courseCompleted']);
        self::assertSame(0, $this->em()->getRepository(Lesson::class)->count([]));
        $result = $this->submit(['yes']);
        self::assertResponseIsSuccessful();
        self::assertTrue($result['courseCompleted']);
        foreach (['desktop', 'mobile'] as $prefix) {
            self::assertStringContainsString('1/1 complété(s)', $result['progress'][$prefix]);
            self::assertStringContainsString('data-sidebar-complete="true"', $result['progress'][$prefix]);
            self::assertStringContainsString('class="collapse show"', $result['progress'][$prefix]);
        }
        $lesson = $this->em()->getRepository(Lesson::class)->findOneBy(['course' => $this->courseId]);
        $id = $lesson->getId();
        $date = $lesson->getStudiedAt();
        foreach ([['yes'], ['no'], ['yes'], []] as $selection) {
            self::assertTrue($this->submit($selection)['courseCompleted']);
            self::assertSame(1, $this->em()->getRepository(Lesson::class)->count([]));
            $lesson = $this->em()->find(Lesson::class, $id);
            self::assertTrue($lesson->isDone());
            self::assertSame($date->getTimestamp(), $lesson->getStudiedAt()->getTimestamp());
        }
        self::assertSame(6, $this->em()->getRepository(ExerciceAttempt::class)->count([]));
        $this->client->request('GET', self::URL);
        self::assertSelectorExists('#completion-button:not([hidden])');
        self::assertSelectorExists('meta[name="turbo-cache-control"][content="no-cache"]');
        self::assertSelectorTextContains('#customer-sidebar', '1/1 complété(s)');
        self::assertSame(6, $this->em()->getRepository(ExerciceAttempt::class)->count([]));
    }

    public function testFirstSuccessOnlyCompletesExplicitCourseAndStandaloneNeverCompletes(): void
    {
        $course = $this->em()->find(Courses::class, $this->courseId);
        $shared = (new Courses())->setName('Partagé')->setSlug('partage')->setSection($course->getSection())
            ->setContentType(CourseContentType::Exercise)->setExercice($course->getExercice());
        $this->em()->persist($shared);
        $this->em()->flush();
        $sharedId = $shared->getId();
        self::assertFalse($this->submit(['yes'], standalone: true)['courseCompleted']);
        self::assertSame(0, $this->em()->getRepository(Lesson::class)->count([]));
        self::assertTrue($this->submit(['yes'])['courseCompleted']);
        self::assertSame(0, $this->em()->getRepository(Lesson::class)->count(['course' => $sharedId]));
        self::assertTrue($this->submit(['yes'], $sharedId)['courseCompleted']);
        self::assertSame(2, $this->em()->getRepository(Lesson::class)->count([]));
    }

    public function testOrphanExerciseStillRecordsItsCorrection(): void
    {
        $this->em()->find(Courses::class, $this->courseId)->setExercice(null);
        $this->em()->flush();
        $this->client->request('GET', '/exercise/'.$this->exerciseId);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-click-words-course-id-value]');
        self::assertFalse($this->submit(['yes'], standalone: true)['courseCompleted']);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->em()->getRepository(ExerciceAttempt::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(Lesson::class)->count([]));
    }

    #[DataProvider('invalidContexts')]
    public function testInvalidContextCannotWrite(string $kind): void
    {
        $course = $this->em()->find(Courses::class, $this->courseId);
        if ('type' === $kind) {
            $course->setContentType(CourseContentType::Twig);
        }
        if ('association' === $kind) {
            $course->setExercice(null);
        }
        $this->em()->flush();
        $this->submit(['yes'], 'missing' === $kind ? 999999 : null);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->em()->getRepository(ExerciceAttempt::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(Lesson::class)->count([]));
    }

    /** @return iterable<array{string}> */
    public static function invalidContexts(): iterable
    {
        yield ['type'];
        yield ['association'];
        yield ['missing'];
    }

    public function testLockedSharedCourseDoesNotInheritExerciseAuthorization(): void
    {
        [, $dictation, , $locked] = DictationFactory::create($this->em());
        $locked->setContentType(CourseContentType::Exercise)->setExercice($this->em()->find(Exercice::class, $this->exerciseId));
        $user = QuizPlayerFactory::user('locked-exercise@example.test')->setRoles(['ROLE_USER']);
        $subscription = (new Subscription())->setEmail($user->getEmail())->setStatus(SubscriptionStatus::ACTIVE)->setIsLifetime(true);
        $user->addSubscription($subscription);
        $this->em()->persist($user);
        $this->em()->persist($subscription);
        $this->em()->flush();
        self::assertContains($dictation->getId(), $user->getDictationPrerequisites());
        $this->client->loginUser($user);
        $page = $this->client->request('GET', self::URL);
        $this->token = $page->filter('[data-controller="click-words"]')->attr('data-click-words-token-value');
        $this->submit(['yes'], $locked->getId());
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em()->getRepository(ExerciceAttempt::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(Lesson::class)->count([]));
        self::assertTrue($this->submit(['yes'])['courseCompleted']);
    }

    public function testInvalidCsrfAndUnauthorizedUserCannotWrite(): void
    {
        $this->submit(['yes'], token: 'invalid');
        self::assertResponseStatusCodeSame(403);
        $user = $this->em()->find(User::class, $this->userId)->setRoles(['ROLE_USER']);
        $this->em()->flush();
        $this->client->loginUser($user);
        $this->submit(['yes']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em()->getRepository(ExerciceAttempt::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(Lesson::class)->count([]));
    }

    public function testManualRouteCannotCompleteOrUndoExercise(): void
    {
        // Obtain a valid token through the unchanged manual content path first.
        $this->em()->find(Courses::class, $this->courseId)->setContentType(CourseContentType::Twig);
        $this->em()->flush();
        $page = $this->client->request('GET', self::URL);
        $token = $page->filter('#completion-button input')->attr('value');
        $this->em()->find(Courses::class, $this->courseId)->setContentType(CourseContentType::Exercise);
        $this->em()->flush();
        $this->client->request('POST', '/course/confirmation/'.$this->courseId, ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em()->getRepository(Lesson::class)->count([]));
        self::assertTrue($this->submit(['yes'])['courseCompleted']);
        $this->client->request('POST', '/course/confirmation/'.$this->courseId, ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->em()->getRepository(Lesson::class)->count(['status' => LessonStatus::DONE]));
    }

    /** @param list<array<string, mixed>> $sentences
     * @param list<string> $selected
     */
    #[DataProvider('completionCases')]
    public function testCompletionUsesExactCorrectionAndUsableContent(array $sentences, array $selected, bool $completed, int $percentage): void
    {
        $this->em()->find(Exercice::class, $this->exerciseId)->setSentences($sentences);
        $this->em()->flush();
        $result = $this->submit($selected);
        self::assertResponseIsSuccessful();
        self::assertSame($completed, $result['courseCompleted']);
        self::assertSame($percentage, $result['percentage']);
        self::assertSame($completed ? 1 : 0, $this->em()->getRepository(Lesson::class)->count([]));
    }

    /** @return iterable<string, array{list<array<string, mixed>>, list<string>, bool, int}> */
    public static function completionCases(): iterable
    {
        yield 'empty' => [[], [], false, 0];
        yield 'empty virtual sentence' => [[['noAnswer' => true, 'words' => []]], [], false, 100];
        yield 'no answer' => [[['noAnswer' => true, 'words' => [['id' => 'x', 'text' => 'Hier']]]], [], true, 100];
        yield 'wrong noAnswer word' => [[['noAnswer' => true, 'words' => [['id' => 'x', 'text' => 'Hier']]]], ['x'], false, 0];
        $words = [];
        for ($i = 0; $i < 201; ++$i) {
            $words[] = ['id' => 'w'.$i, 'text' => 'Verbe', 'isAnswer' => true];
        }
        yield 'rounded with missed answer' => [[['words' => $words]], array_slice(array_column($words, 'id'), 1), false, 100];
        yield 'rounded with wrong answer' => [[['words' => $words]], [...array_column($words, 'id'), 'unknown'], false, 100];
        yield 'unusable without answers' => [[['words' => [['text' => 'Mot']]]], [], false, 0];
    }
}
