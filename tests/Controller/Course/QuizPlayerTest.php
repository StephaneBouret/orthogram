<?php

namespace App\Tests\Controller\Course;

use App\Entity\Courses;
use App\Entity\Lesson;
use App\Entity\Quiz;
use App\Entity\QuizAttempt;
use App\Entity\QuizQuestion;
use App\Enum\CourseContentType;
use App\Enum\LessonStatus;
use App\Tests\Support\QuizPlayerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class QuizPlayerTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $database;
    private int $courseId;
    private string $token;
    private ?string $previousEnvUrl;
    private ?string $previousServerUrl;

    protected function setUp(): void
    {
        $this->previousEnvUrl = $_ENV['DATABASE_URL'] ?? null;
        $this->previousServerUrl = $_SERVER['DATABASE_URL'] ?? null;
        $this->database = str_replace('\\', '/', dirname(__DIR__, 3).'/var/quiz-player-'.bin2hex(random_bytes(8)).'.sqlite');
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///'.$this->database;
        $this->client = self::createClient();
        $this->client->catchExceptions(false);
        self::assertSame($this->database, $this->em()->getConnection()->getParams()['path']);
        (new SchemaTool($this->em()))->createSchema($this->em()->getMetadataFactory()->getAllMetadata());
        [$user, $course] = QuizPlayerFactory::create($this->em());
        $this->courseId = $course->getId();
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/courses/quiz-formation/quiz-section/quiz-cours');
        self::assertResponseIsSuccessful();
        $this->token = $crawler->filter('[data-controller="quiz"]')->attr('data-quiz-token-value');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->database) && is_file($this->database)) {
            unlink($this->database);
        }
        foreach (['ENV' => $this->previousEnvUrl, 'SERVER' => $this->previousServerUrl] as $kind => $value) {
            if ('ENV' === $kind) {
                if (null === $value) {
                    unset($_ENV['DATABASE_URL']);
                } else {
                    $_ENV['DATABASE_URL'] = $value;
                }
            } else {
                if (null === $value) {
                    unset($_SERVER['DATABASE_URL']);
                } else {
                    $_SERVER['DATABASE_URL'] = $value;
                }
            }
        }
    }

    private function em(): EntityManagerInterface
    {
        $registry = self::getContainer()->get('doctrine');
        $manager = $registry->getManager();
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Doctrine ORM requis.');
        }
        if (!$manager->isOpen()) {
            $registry->resetManager();
        }

        return self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $action, array $payload = [], int $status = 200, ?string $token = null): array
    {
        $this->client->request('POST', '/course/'.$this->courseId.'/quiz/'.$action, [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token ?? $this->token], json_encode((object) $payload, JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame($status);

        return json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function state(?int $attemptId = null, int $status = 200): array
    {
        $this->client->request('GET', '/course/'.$this->courseId.'/quiz'.(null === $attemptId ? '' : '?attemptId='.$attemptId));
        self::assertResponseStatusCodeSame($status);

        return json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function answer(array $state, bool $correct = true): array
    {
        $answers = $state['question']['answers'];
        $selected = $correct ? ($state['question']['multiple'] ? [$answers[0]['id'], $answers[1]['id']] : [$answers[0]['id']]) : [end($answers)['id']];

        return $this->post('answer', ['attemptId' => $state['attemptId'], 'questionId' => $state['question']['id'], 'selectedIds' => $selected]);
    }

    public function testReadOnlyIntroEscapesHtmlAndDoesNotLeakOrCreate(): void
    {
        self::assertSelectorNotExists('[data-controller="quiz"] img');
        self::assertSelectorTextContains('[data-quiz-target="title"]', '<img');
        self::assertSelectorNotExists('#completion-button form');
        self::assertStringNotContainsString('SECRET', $this->client->getResponse()->getContent());
        self::assertSame(2, $this->state()['total']);
        self::assertSame(0, $this->em()->getRepository(QuizAttempt::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(Lesson::class)->count([]));
        $state = $this->post('start');
        self::assertSame(2, $state['total']);
        self::assertSame([], $state['review']);
        self::assertArrayNotHasKey('explanation', $state['question']);
        self::assertArrayNotHasKey('correct', $state['question']['answers'][0]);
        self::assertStringNotContainsString('SECRET', json_encode($state));
        self::assertSame($state['attemptId'], $this->post('start')['attemptId']);
    }

    public function testResumeWrongAnswerFinishZeroAndRestartPreserveHistory(): void
    {
        $state = $this->answer($this->post('start'), false);
        self::assertSame(1, $state['validated']);
        self::assertFalse($state['review'][0]['correct']);
        self::assertSame('SECRET première correction', $state['review'][0]['explanation']);
        self::assertStringNotContainsString('SECRET deuxième', json_encode($state));
        $resumed = $this->state();
        self::assertSame($state['question']['id'], $resumed['question']['id']);
        $state = $this->answer($resumed, false);
        self::assertNull($this->state()['question']);
        self::assertFalse($this->state()['completed']);
        self::assertSame(0, $this->em()->getRepository(Lesson::class)->count([]));
        $finished = $this->post('finish', ['attemptId' => $state['attemptId'], 'score' => 999, 'total' => 999]);
        self::assertSame(0, $finished['score']);
        self::assertTrue($finished['completed']);
        self::assertSame($finished, $this->post('finish', ['attemptId' => $state['attemptId']]));
        self::assertSame(1, $this->em()->getRepository(Lesson::class)->count(['status' => LessonStatus::DONE]));
        $next = $this->post('restart', ['attemptId' => $state['attemptId']]);
        self::assertNotSame($finished['attemptId'], $next['attemptId']);
        self::assertSame($next['attemptId'], $this->post('restart', ['attemptId' => $state['attemptId']])['attemptId']);
        self::assertSame($finished, $this->state($finished['attemptId']));
        self::assertSame(2, $this->em()->getRepository(QuizAttempt::class)->count([]));
        self::assertSame(1, $this->em()->getRepository(Lesson::class)->count(['status' => LessonStatus::DONE]));
    }

    public function testReplaysAndFutureQuestionAreProtected(): void
    {
        $state = $this->post('start');
        $question = $state['question'];
        $future = $this->em()->getRepository(QuizQuestion::class)->findOneBy(['position' => 1]);
        $this->post('answer', ['attemptId' => $state['attemptId'], 'questionId' => $future->getId(), 'selectedIds' => [$future->getAnswers()->first()->getId()]], 409);
        $this->post('finish', ['attemptId' => $state['attemptId']], 409);
        $this->post('restart', ['attemptId' => $state['attemptId']], 409);
        $answered = $this->answer($this->state());
        $payload = ['attemptId' => $state['attemptId'], 'questionId' => $question['id'], 'selectedIds' => [$question['answers'][0]['id']]];
        self::assertSame($answered, $this->post('answer', $payload));
        $payload['selectedIds'] = [$question['answers'][1]['id']];
        $this->post('answer', $payload, 409);
        self::assertSame($answered, $this->state());
        $completed = $this->answer($answered);
        self::assertSame(2, $completed['score']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidSelections(): iterable
    {
        yield 'empty' => [[]];
        yield 'string' => [['1']];
        yield 'zero' => [[0]];
        yield 'negative' => [[-1]];
        yield 'float' => [[1.1]];
        yield 'boolean' => [[true]];
        yield 'foreign' => [[999999]];
        yield 'duplicate' => [[1, 1]];
        yield 'two single' => [[1, 2]];
        yield 'object' => [['id' => 1]];
        yield 'null' => [null];
    }

    #[DataProvider('invalidSelections')]
    public function testInvalidSelectionsDoNotWrite(mixed $selection): void
    {
        $state = $this->post('start');
        $this->post('answer', ['attemptId' => $state['attemptId'], 'questionId' => $state['question']['id'], 'selectedIds' => $selection], 400);
        self::assertSame($state, $this->state());
    }

    /** @return iterable<string, array{string}> */
    public static function mutations(): iterable
    {
        foreach (['start', 'answer', 'finish', 'restart'] as $action) {
            yield $action => [$action];
        }
    }

    #[DataProvider('mutations')]
    public function testEveryMutationChecksCsrfAndMalformedJson(string $action): void
    {
        $this->client->request('POST', '/course/'.$this->courseId.'/quiz/'.$action, [], [], [], '{}');
        self::assertResponseStatusCodeSame(403);
        $this->post($action, [], 403, '');
        $this->post($action, [], 403, 'invalid');
        foreach (['{', '[]', 'null'] as $body) {
            $this->client->request('POST', '/course/'.$this->courseId.'/quiz/'.$action, [], [], ['HTTP_X_CSRF_TOKEN' => $this->token], $body);
            self::assertResponseStatusCodeSame(400);
        }
    }

    public function testInvalidQuizAndForeignQuestion(): void
    {
        $state = $this->post('start');
        $this->post('answer', ['attemptId' => $state['attemptId'], 'questionId' => 999999, 'selectedIds' => [1]], 400);
        $quiz = $this->em()->find(Courses::class, $this->courseId)->getQuiz();
        $quiz->getQuestions()->first()->getAnswers()->first()->setCorrect(false);
        $this->em()->flush();
        $state = $this->answer($this->answer($this->state()));
        $this->post('finish', ['attemptId' => $state['attemptId']]);
        $this->post('restart', ['attemptId' => $state['attemptId']], 409);
        $quiz = $this->em()->find(Courses::class, $this->courseId)->getQuiz();
        foreach ($quiz->getQuestions()->toArray() as $question) {
            $quiz->removeQuestion($question);
        }
        $this->em()->flush();
        $this->post('restart', ['attemptId' => $state['attemptId']], 409);
    }

    public function testEmptyQuizCannotStartAndJsonObjectIsNotASelectionList(): void
    {
        $state = $this->post('start');
        $this->client->request('POST', '/course/'.$this->courseId.'/quiz/answer', [], [], ['HTTP_X_CSRF_TOKEN' => $this->token],
            '{"attemptId":'.$state['attemptId'].',"questionId":'.$state['question']['id'].',"selectedIds":{"0":'.$state['question']['answers'][0]['id'].'}}');
        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->state()['validated']);
        $course = $this->em()->find(Courses::class, $this->courseId);
        $empty = (new Quiz())->setTitle('Vide');
        $course->setQuiz($empty);
        $this->em()->persist($empty);
        $this->em()->flush();
        $this->post('start', [], 409);
        self::assertSame(1, $this->em()->getRepository(QuizAttempt::class)->count([]));
    }

    public function testSnapshotSurvivesEditAndDeletionAndNewAttemptUsesCurrentContent(): void
    {
        $state = $this->post('start');
        $question = $this->em()->find(QuizQuestion::class, $state['question']['id']);
        $quiz = $question->getQuiz();
        $quiz->setTitle('Nouveau titre');
        $question->setExplanation('Explication modifiée');
        $quiz->removeQuestion($question);
        $this->em()->flush();
        $state = $this->answer($this->answer($this->state()));
        self::assertSame(2, $state['score']);
        self::assertSame('SECRET première correction', $state['review'][0]['explanation']);
        $this->post('finish', ['attemptId' => $state['attemptId']]);
        $next = $this->post('restart', ['attemptId' => $state['attemptId']]);
        self::assertSame(1, $next['total']);
        self::assertSame('Nouveau titre', $next['title']);
        self::assertSame(2, $this->state($state['attemptId'])['total']);
    }

    public function testOwnershipAccessAndCourseBinding(): void
    {
        $state = $this->post('start');
        $other = QuizPlayerFactory::user('other@example.test');
        $this->em()->persist($other);
        $this->em()->flush();
        $this->client->loginUser($other);
        $this->state($state['attemptId'], 404);
        foreach (['answer', 'finish', 'restart'] as $action) {
            $this->post($action, ['attemptId' => $state['attemptId']], 404);
        }
        $other = $this->em()->getRepository(\App\Entity\User::class)->findOneBy(['email' => 'other@example.test']);
        $other->setRoles([]);
        $this->em()->flush();
        $this->client->loginUser($other);
        $this->client->catchExceptions(true);
        $this->state(null, 403);
        foreach (['start', 'answer', 'finish', 'restart'] as $action) {
            $this->post($action, ['attemptId' => $state['attemptId']], 403);
        }
        $this->client->getCookieJar()->clear();
        foreach (['', '/start', '/answer', '/finish', '/restart'] as $path) {
            $this->client->request('' === $path ? 'GET' : 'POST', '/course/'.$this->courseId.'/quiz'.$path);
            self::assertResponseRedirects();
        }
    }

    public function testChangedQuizDeniesOldAttemptAndSharedQuizCompletesOnlyItsCourse(): void
    {
        $state = $this->post('start');
        $course = $this->em()->find(Courses::class, $this->courseId);
        $other = (new Courses())->setName('Autre cours')->setSlug('autre-cours')->setSection($course->getSection())->setContentType(CourseContentType::Quiz)->setQuiz($course->getQuiz());
        $this->em()->persist($other);
        $this->em()->flush();
        $oldCourseId = $this->courseId;
        $this->courseId = $other->getId();
        $this->state($state['attemptId'], 404);
        $this->courseId = $oldCourseId;
        $state = $this->answer($this->answer($state));
        $this->post('finish', ['attemptId' => $state['attemptId']]);
        self::assertSame(1, $this->em()->getRepository(Lesson::class)->count(['course' => $this->courseId]));
        self::assertSame(0, $this->em()->getRepository(Lesson::class)->count(['course' => $other->getId()]));
        $course = $this->em()->find(Courses::class, $this->courseId);
        $replacement = (new Quiz())->setTitle('Remplacement');
        $course->setQuiz($replacement);
        $this->em()->persist($replacement);
        $this->em()->flush();
        $this->state($state['attemptId'], 404);
        self::assertSame(1, $this->em()->getRepository(QuizAttempt::class)->count([]));
    }

    public function testSubscriptionRightsAreRecheckedAfterStarting(): void
    {
        $user = $this->em()->getRepository(\App\Entity\User::class)->findOneBy(['email' => 'quiz-player@example.test']);
        $user->setRoles([]);
        $subscription = (new \App\Entity\Subscription())->setUser($user)->setEmail($user->getEmail())
            ->setStatus(\App\Enum\SubscriptionStatus::ACTIVE)->setStartsAt(new \DateTimeImmutable('-1 day'))->setEndsAt(new \DateTimeImmutable('+1 day'));
        $this->em()->persist($subscription);
        $this->em()->flush();
        $this->client->loginUser($user);
        $state = $this->post('start');
        $this->em()->getRepository(\App\Entity\Subscription::class)->findOneBy([])->setEndsAt(new \DateTimeImmutable('-1 hour'));
        $this->em()->flush();
        $this->state($state['attemptId'], 403);
        $this->post('finish', ['attemptId' => $state['attemptId']], 403);
    }

    public function testDeletedCourseIsUnavailableWithoutDeletingAttempt(): void
    {
        $state = $this->post('start');
        $this->em()->remove($this->em()->find(Courses::class, $this->courseId));
        $this->em()->flush();
        $this->client->catchExceptions(true);
        $this->client->request('GET', '/course/'.$this->courseId.'/quiz?attemptId='.$state['attemptId']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->em()->getRepository(QuizAttempt::class)->count([]));
    }

    public function testManualQuizCompletionDeniedOrdinaryTogglePreservedAndExistingDoneKept(): void
    {
        $this->client->catchExceptions(true);
        $this->client->request('POST', '/course/confirmation/'.$this->courseId, ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $course = $this->em()->find(Courses::class, $this->courseId);
        $course->setContentType(CourseContentType::Twig);
        $this->em()->flush();
        $crawler = $this->client->request('GET', '/courses/quiz-formation/quiz-section/quiz-cours');
        $form = $crawler->filter('#completion-button form')->form();
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertSame(1, $this->em()->getRepository(Lesson::class)->count(['status' => LessonStatus::DONE]));
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('#completion-button form')->form());
        self::assertSame(1, $this->em()->getRepository(Lesson::class)->count(['status' => LessonStatus::STUDY]));
        $lesson = $this->em()->getRepository(Lesson::class)->findOneBy([]);
        $lesson->setStatus(LessonStatus::DONE)->setStudiedAt(new \DateTimeImmutable('2020-01-01'));
        $this->em()->find(Courses::class, $this->courseId)->setContentType(CourseContentType::Quiz);
        $this->em()->flush();
        $state = $this->answer($this->answer($this->post('start'), false), false);
        $this->post('finish', ['attemptId' => $state['attemptId']]);
        self::assertSame('2020-01-01', $this->em()->getRepository(Lesson::class)->findOneBy([])->getStudiedAt()->format('Y-m-d'));
    }
}
