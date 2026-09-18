<?php

namespace App\Tests\Controller;

use App\Entity\Courses;
use App\Entity\Quiz;
use App\Entity\QuizAttempt;
use App\Entity\User;
use App\Enum\CourseContentType;
use App\Services\QuizResultsService;
use App\Tests\Support\QuizPlayerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** @phpstan-import-type Snapshot from QuizAttempt */
final class QuizResultsTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $database;
    private ?string $previousEnvUrl;
    private ?string $previousServerUrl;
    private User $user;
    private Courses $course;

    protected function setUp(): void
    {
        $this->previousEnvUrl = $_ENV['DATABASE_URL'] ?? null;
        $this->previousServerUrl = $_SERVER['DATABASE_URL'] ?? null;
        $this->database = str_replace('\\', '/', dirname(__DIR__, 2).'/var/quiz-results-'.bin2hex(random_bytes(8)).'.sqlite');
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///'.$this->database;
        $this->client = self::createClient();
        self::assertSame($this->database, $this->em()->getConnection()->getParams()['path']);
        (new SchemaTool($this->em()))->createSchema($this->em()->getMetadataFactory()->getAllMetadata());
        [$this->user, $this->course] = QuizPlayerFactory::create($this->em());
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->database) && is_file($this->database)) {
            unlink($this->database);
        }
        if (null === $this->previousEnvUrl) {
            unset($_ENV['DATABASE_URL']);
        } else {
            $_ENV['DATABASE_URL'] = $this->previousEnvUrl;
        }
        if (null === $this->previousServerUrl) {
            unset($_SERVER['DATABASE_URL']);
        } else {
            $_SERVER['DATABASE_URL'] = $this->previousServerUrl;
        }
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function service(): QuizResultsService
    {
        return self::getContainer()->get(QuizResultsService::class);
    }

    /** @param Snapshot|null $snapshot */
    private function attempt(int $score, bool $complete = true, ?array $snapshot = null, ?User $user = null, ?Courses $course = null): QuizAttempt
    {
        $course ??= $this->course;
        $quiz = $course->getQuiz();
        if (null === $snapshot) {
            $snapshot = ['version' => 1, 'title' => $quiz->getTitle(), 'questions' => []];
            foreach ($quiz->getQuestions() as $question) {
                $answers = [];
                foreach ($question->getAnswers() as $answer) {
                    $answers[] = ['id' => $answer->getId(), 'content' => $answer->getContent(), 'correct' => $answer->isCorrect()];
                }
                $snapshot['questions'][] = ['id' => $question->getId(), 'title' => $question->getTitle(),
                    'text' => $question->getText(), 'multiple' => $question->isMultiple(), 'theme' => $question->getTheme(),
                    'explanation' => $question->getExplanation(), 'answers' => $answers];
            }
        }
        $attempt = new QuizAttempt($user ?? $this->user, $course, $quiz, $snapshot);
        if ($complete) {
            foreach ($snapshot['questions'] as $index => $question) {
                $correct = $index < $score;
                $selected = array_column(array_filter($question['answers'], static fn ($a) => $a['correct'] === $correct), 'id');
                $attempt->record($question['multiple'] ? $selected : array_slice($selected, 0, 1), $correct);
            }
            $attempt->complete();
        }
        $this->em()->persist($attempt);
        $this->em()->flush();

        return $attempt;
    }

    public function testCatalogFirstAttemptAndActiveAttemptNeverEraseLastCompleted(): void
    {
        $exercise = (new Courses())->setName('Exercice hors périmètre')->setSlug('exercice')
            ->setSection($this->course->getSection())->setContentType(CourseContentType::Exercise);
        $this->em()->persist($exercise);
        $this->em()->flush();
        $before = $this->service()->results();
        self::assertCount(1, $before);
        self::assertSame('not_started', $before[0]['status']);
        self::assertNull($before[0]['latest']);
        $active = $this->attempt(0, false);
        $during = $this->service()->results()[0];
        self::assertSame('in_progress', $during['status']);
        self::assertNull($during['latest']);
        self::assertNull($during['best']);
        self::assertSame(0, $during['completedCount']);
        $first = $this->attempt(1);
        $after = $this->service()->results()[0];
        self::assertSame('completed', $after['status']);
        self::assertTrue($after['hasInProgress']);
        self::assertSame($active->getId(), $after['inProgressAttemptId']);
        self::assertSame($first->getId(), $after['latest']['attemptId']);
        self::assertNull($after['latest']['delta']);
        self::assertFalse($after['latest']['contentChanged']);
        self::assertSame(50, $after['best']['percentage']);
        self::assertSame(1, $after['completedCount']);
        self::assertArrayNotHasKey('questions', $after['latest']);
        self::assertArrayNotHasKey('responses', $after['latest']);
    }

    public function testCompletionDateThenIdOrderingAndFactualDeltas(): void
    {
        $first = $this->attempt(2);
        $second = $this->attempt(1);
        $third = $this->attempt(1);
        foreach ([$first, $second, $third] as $attempt) {
            (new \ReflectionProperty(QuizAttempt::class, 'completedAt'))->setValue($attempt, new \DateTimeImmutable('2026-09-01 12:00:00'));
        }
        // A larger id may have an older completion date (e.g. imported history).
        $older = $this->attempt(0);
        (new \ReflectionProperty(QuizAttempt::class, 'completedAt'))->setValue($older, new \DateTimeImmutable('2026-08-01'));
        $this->attempt(0, false);
        $this->em()->flush();
        $result = $this->service()->results()[0];
        self::assertSame([$older->getId(), $first->getId(), $second->getId(), $third->getId()], array_column($result['history'], 'attemptId'));
        self::assertSame($third->getId(), $result['latest']['attemptId']);
        self::assertSame([null, 100, -50, 0], array_column($result['history'], 'delta'));
        self::assertSame('Stable', $result['latest']['deltaLabel']);
        self::assertSame('-50 points', $result['history'][2]['deltaLabel']);
    }

    public function testContentChangeWithSameQuestionCountAndRename(): void
    {
        $a = $this->attempt(2);
        $changed = $a->getSnapshot();
        $changed['questions'][0]['answers'][0]['correct'] = false;
        $b = $this->attempt(2, snapshot: $changed);
        $renamed = $a->getSnapshot();
        $renamed['title'] = 'Un simple renommage';
        $renamed['version'] = 2;
        $c = $this->attempt(1, snapshot: $renamed);
        $result = $this->service()->results()[0];
        self::assertSame($c->getId(), $result['latest']['attemptId']);
        self::assertNull($result['latest']['delta']);
        self::assertTrue($result['mixedContent']);
        self::assertSame([false, true, true], array_column($result['history'], 'contentChanged'));
        self::assertSame($a->getId(), $result['best']['attemptId']);
        self::assertNotSame($b->getId(), $result['best']['attemptId']);
        $this->attempt(2, snapshot: $renamed);
        $result = $this->service()->results()[0];
        self::assertSame(50, $result['latest']['delta']);
        self::assertFalse($result['latest']['contentChanged']);
    }

    public function testBestRatioAndRoundedDeltaAndZeroTotal(): void
    {
        $first = $this->attempt(1);
        $second = $this->attempt(2);
        // Stored totals may be inconsistent in legacy data: compare actual ratios,
        // not raw scores or already rounded percentages.
        (new \ReflectionProperty(QuizAttempt::class, 'total'))->setValue($first, 3);
        (new \ReflectionProperty(QuizAttempt::class, 'total'))->setValue($second, 7);
        $this->em()->flush();
        $result = $this->service()->results()[0];
        self::assertSame(33, $result['best']['percentage']);
        self::assertSame($first->getId(), $result['best']['attemptId']);
        self::assertSame(29, $result['latest']['percentage']);
        self::assertSame(-4, $result['latest']['delta']);
        (new \ReflectionProperty(QuizAttempt::class, 'total'))->setValue($second, 6);
        $this->em()->flush();
        self::assertSame('Stable', $this->service()->results()[0]['latest']['deltaLabel']);
        (new \ReflectionProperty(QuizAttempt::class, 'total'))->setValue($second, 0);
        $this->em()->flush();
        $result = $this->service()->results()[0];
        self::assertNull($result['latest']['percentage']);
        self::assertNull($result['latest']['delta']);
        self::assertSame($first->getId(), $result['best']['attemptId']);
    }

    public function testQuestionOrderChoicesTextAndExplanationsAffectComparability(): void
    {
        $original = $this->attempt(2)->getSnapshot();
        $variants = [];
        $variant = $original;
        $variant['questions'] = array_reverse($variant['questions']);
        $variants[] = $variant;
        $variant = $original;
        $variant['questions'][0]['text'] = 'Une autre phrase.';
        $variants[] = $variant;
        $variant = $original;
        $variant['questions'][0]['answers'] = array_reverse($variant['questions'][0]['answers']);
        $variants[] = $variant;
        $variant = $original;
        $variant['questions'][0]['answers'][0]['content'] = 'Autre proposition';
        $variants[] = $variant;
        $variant = $original;
        $variant['questions'][0]['explanation'] = 'Autre explication';
        $variants[] = $variant;
        foreach ($variants as $snapshot) {
            $attempt = $this->attempt(1, snapshot: $snapshot);
            $result = $this->service()->results()[0];
            self::assertTrue($result['latest']['contentChanged']);
            self::assertNull($result['latest']['delta']);
            self::assertSame($attempt->getId(), $result['best']['attemptId']);
        }
    }

    public function testAccountsAndCoursesNeverShareResultsEvenForAnAdmin(): void
    {
        $mine = $this->attempt(1);
        $other = QuizPlayerFactory::user('other-results@example.test');
        $this->em()->persist($other);
        $this->attempt(2, user: $other);
        $course = (new Courses())->setName('Autre cours')->setSlug('autre')->setSection($this->course->getSection())
            ->setContentType(CourseContentType::Quiz)->setQuiz($this->course->getQuiz());
        $this->em()->persist($course);
        $separate = $this->attempt(0, course: $course);
        $results = $this->service()->results();
        self::assertCount(2, $results);
        self::assertSame([$mine->getId(), $separate->getId()], array_column(array_column($results, 'latest'), 'attemptId'));
        self::assertSame([1, 1], array_column($results, 'completedCount'));
        $this->client->loginUser($other);
        self::assertSame([1, 0], array_column($this->service()->results(), 'completedCount'));
        $this->client->request('GET', '/mes-resultats/tentatives/'.$mine->getId().'?userId='.$this->user->getId());
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('SECRET première', $this->client->getResponse()->getContent());
    }

    public function testFrozenCorrectionSurvivesEditingReassignmentAndDeletionOfQuizWithoutWrites(): void
    {
        $attempt = $this->attempt(1);
        $originalQuiz = $this->course->getQuiz();
        $originalQuiz->setTitle('Titre modifié');
        $originalQuiz->getQuestions()->first()->setExplanation('Explication modifiée');
        $replacement = (new Quiz())->setTitle('Remplacement');
        $this->em()->persist($replacement);
        $this->course->setQuiz($replacement);
        $this->em()->flush();
        $before = $this->databaseState();
        $this->service()->results();
        $crawler = $this->client->request('GET', '/mes-resultats/tentatives/'.$attempt->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Quiz <img');
        self::assertSelectorNotExists('main img');
        self::assertSelectorTextContains('main', 'SECRET première correction');
        self::assertSelectorTextContains('main', 'Votre choix');
        self::assertCount(2, $crawler->filter('details'));
        self::assertStringNotContainsString('Explication modifiée', $this->client->getResponse()->getContent());
        self::assertSelectorNotExists('main form, main [data-controller="quiz"]');
        self::assertSelectorExists('meta[name="turbo-cache-control"][content="no-cache"]');
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private'));
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertSame($before, $this->databaseState());
        $this->em()->remove($this->em()->find(Quiz::class, $originalQuiz->getId()));
        $this->em()->flush();
        $this->client->request('GET', '/mes-resultats/tentatives/'.$attempt->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'SECRET première correction');
    }

    public function testUnfinishedMissingAndAnonymousCorrectionAreRefused(): void
    {
        $attempt = $this->attempt(0, false);
        foreach ([$attempt->getId(), 999999] as $id) {
            $this->client->request('GET', '/mes-resultats/tentatives/'.$id);
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString('SECRET première', $this->client->getResponse()->getContent());
            self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        }
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/mes-resultats/tentatives/'.$attempt->getId());
        self::assertResponseRedirects('/login');
    }

    public function testExpiredAccessKeepsNumbersButDeniesCorrectionAndPassation(): void
    {
        $attempt = $this->attempt(1);
        $this->user->setRoles([]);
        $subscription = (new \App\Entity\Subscription())->setUser($this->user)->setEmail($this->user->getEmail())
            ->setStatus(\App\Enum\SubscriptionStatus::ACTIVE)->setStartsAt(new \DateTimeImmutable('-2 days'))->setEndsAt(new \DateTimeImmutable('-1 day'));
        $this->user->addSubscription($subscription);
        $this->em()->persist($subscription);
        $this->em()->flush();
        $this->client->loginUser($this->user);
        $result = $this->service()->results()[0];
        self::assertSame(50, $result['latest']['percentage']);
        self::assertNull($result['courseUrl']);
        self::assertNull($result['latest']['correctionUrl']);
        $before = $this->databaseState();
        $this->client->request('GET', '/mes-resultats/tentatives/'.$attempt->getId());
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private'));
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertSame($before, $this->databaseState());
    }

    public function testActiveSubscriptionAllowsAnOrdinaryAccountToReadCorrection(): void
    {
        $attempt = $this->attempt(1);
        $this->user->setRoles([]);
        $subscription = (new \App\Entity\Subscription())->setUser($this->user)->setEmail($this->user->getEmail())
            ->setStatus(\App\Enum\SubscriptionStatus::ACTIVE)->setStartsAt(new \DateTimeImmutable('-1 day'))->setEndsAt(new \DateTimeImmutable('+1 day'));
        $this->user->addSubscription($subscription);
        $this->em()->persist($subscription);
        $this->em()->flush();
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/mes-resultats/tentatives/'.$attempt->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'SECRET première correction');
    }

    public function testMissingCourseArchivesAttemptsSeparatelyAndDeniesCorrection(): void
    {
        $a = $this->attempt(1);
        $b = $this->attempt(2);
        $this->em()->remove($this->course);
        $this->em()->flush();
        $this->em()->clear();
        $results = $this->service()->results();
        self::assertCount(2, $results);
        self::assertSame(['archived:'.$a->getId(), 'archived:'.$b->getId()], array_column($results, 'key'));
        self::assertSame([null, null], array_column($results, 'courseUrl'));
        self::assertSame([true, true], array_column($results, 'archived'));
        self::assertSame([1, 1], array_column($results, 'completedCount'));
        $this->client->request('GET', '/mes-resultats/tentatives/'.$a->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testReassignedQuizKeepsSeparateHistoryAndCurrentCatalog(): void
    {
        $a = $this->attempt(1);
        $replacement = (new Quiz())->setTitle('Nouveau test');
        $this->em()->persist($replacement);
        $this->course->setQuiz($replacement);
        $this->em()->flush();
        $results = $this->service()->results();
        self::assertCount(2, $results);
        self::assertSame('not_started', $results[0]['status']);
        self::assertFalse($results[0]['archived']);
        self::assertNotNull($results[0]['courseUrl']);
        self::assertSame($a->getSnapshot()['title'], $results[1]['title']);
        self::assertTrue($results[1]['archived']);
        self::assertNull($results[1]['courseUrl']);
        self::assertNotNull($results[1]['latest']['correctionUrl']);
    }

    public function testDeletedQuizArchivesEachAttemptWithoutLosingItsCorrectionLink(): void
    {
        $a = $this->attempt(1);
        $b = $this->attempt(2);
        $this->em()->remove($this->course->getQuiz());
        $this->em()->flush();
        $this->em()->clear();
        $results = $this->service()->results();
        self::assertCount(2, $results);
        self::assertSame(['archived:'.$a->getId(), 'archived:'.$b->getId()], array_column($results, 'key'));
        foreach ($results as $result) {
            self::assertNull($result['quizId']);
            self::assertNull($result['courseUrl']);
            self::assertNotNull($result['latest']['correctionUrl']);
            self::assertSame(1, $result['completedCount']);
        }
    }

    public function testInactiveAccountFollowsExistingSessionPolicy(): void
    {
        $attempt = $this->attempt(1);
        $this->user->setAccountStatus(\App\Enum\UserAccountStatus::SUSPENDED);
        $this->em()->flush();
        $this->client->request('GET', '/mes-resultats/tentatives/'.$attempt->getId());
        self::assertResponseRedirects('/login');
        self::assertStringNotContainsString('SECRET première', $this->client->getResponse()->getContent());
    }

    /** @return array<string, mixed> */
    private function databaseState(): array
    {
        $connection = $this->em()->getConnection();

        return ['attempts' => $connection->fetchAllAssociative('SELECT * FROM quiz_attempt ORDER BY id'),
            'lessons' => $connection->fetchAllAssociative('SELECT * FROM lesson ORDER BY id')];
    }
}
