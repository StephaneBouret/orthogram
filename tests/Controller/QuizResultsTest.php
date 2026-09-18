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
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testResultsPageRequiresAuthenticationAndInactiveAccountsAreDisconnected(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/mes-resultats');
        self::assertResponseRedirects('/login');
        $user = $this->em()->find(User::class, $this->user->getId());
        $user->setAccountStatus(\App\Enum\UserAccountStatus::SUSPENDED);
        $this->em()->flush();
        $this->client->loginUser($user);
        $this->client->request('GET', '/mes-resultats');
        self::assertResponseRedirects('/login');
    }

    public function testResultsPageOffersStartWithoutCreatingAnAttempt(): void
    {
        $before = $this->databaseState();
        $crawler = $this->client->request('GET', '/mes-resultats');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Pas encore passé');
        self::assertSelectorNotExists('main canvas');
        self::assertSame('/courses/quiz-formation/quiz-section/quiz-cours', $crawler->selectLink('Commencer le test')->attr('href'));
        self::assertSelectorExists('nav a[href="/mes-resultats"].active[aria-current="page"]');
        self::assertSame($before, $this->databaseState());
    }

    public function testActiveOnlyPageDoesNotPresentPartialScore(): void
    {
        $attempt = $this->attempt(0, false);
        $attempt->record([$attempt->getSnapshot()['questions'][0]['answers'][0]['id']], true);
        $this->em()->flush();
        $this->client->request('GET', '/mes-resultats');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'En cours');
        self::assertSelectorTextContains('main', 'Continuer le test');
        self::assertSelectorNotExists('main canvas, main .results-score');
        self::assertSelectorNotExists('main a[href*="/tentatives/"]');
    }

    /** @return iterable<string, array{int}> */
    public static function chartScores(): iterable
    {
        yield 'zero' => [0];
        yield 'half' => [1];
        yield 'perfect' => [2];
    }

    #[DataProvider('chartScores')]
    public function testPageChartsUseLastCompletedAndExactCorrection(int $score): void
    {
        $finished = $this->attempt($score);
        $this->attempt(0, false);
        $before = $this->databaseState();
        $crawler = $this->client->request('GET', '/mes-resultats');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.results-score', (50 * $score).' %');
        self::assertSelectorTextContains('main', 'Une tentative est en cours.');
        self::assertSelectorTextContains('main', 'Continuer le test');
        self::assertSame('/mes-resultats/tentatives/'.$finished->getId(), $crawler->selectLink('Revoir ma correction')->attr('href'));
        $chart = json_decode($crawler->filter('canvas')->attr('data-symfony--ux-chartjs--chart-view-value'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('doughnut', $chart['type']);
        self::assertSame([$score, 2 - $score], $chart['data']['datasets'][0]['data']);
        self::assertFalse($chart['options']['plugins']['legend']['display']);
        self::assertSelectorNotExists('main img');
        self::assertStringNotContainsString('SECRET première', $this->client->getResponse()->getContent());
        self::assertSame($before, $this->databaseState());
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private'));
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertSelectorExists('meta[name="turbo-cache-control"][content="no-cache"]');
    }

    public function testPageOnlyShowsConnectedAccountsScoresAndCanBeEmpty(): void
    {
        $this->attempt(2);
        $other = QuizPlayerFactory::user('page-other@example.test')->setRoles([]);
        $this->em()->persist($other);
        $this->em()->flush();
        $this->client->loginUser($other);
        $this->client->request('GET', '/mes-resultats?userId='.$this->user->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Aucun résultat pour le moment');
        self::assertSelectorNotExists('main article, main canvas');
        self::assertSelectorExists('main a[href="/mon-abonnement"]');
    }

    public function testPagePreservesExpiredResultsButOffersNoForbiddenActions(): void
    {
        $this->attempt(1);
        $this->user->setRoles([]);
        $this->em()->flush();
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/mes-resultats');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', '50 %');
        self::assertSelectorTextContains('main', 'Résultat conservé');
        self::assertSelectorNotExists('main article .results-actions a');
        self::assertSelectorExists('main article a[href*="/historique/"]');
    }

    public function testPageShowsContentChangeAndBestScopeAndArchives(): void
    {
        $a = $this->attempt(2);
        $changed = $a->getSnapshot();
        $changed['questions'][0]['explanation'] = 'Nouvelle explication';
        $this->attempt(1, snapshot: $changed);
        $this->client->request('GET', '/mes-resultats');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.results-stats', 'Contenu du test modifié');
        self::assertSelectorTextContains('.results-stats', 'pour ce contenu du test');
        $this->em()->remove($this->em()->find(Courses::class, $this->course->getId()));
        $this->em()->flush();
        $this->client->request('GET', '/mes-resultats');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#results-archives', 'Archives');
        self::assertSelectorCount(2, 'main article');
        self::assertSelectorNotExists('main article .results-actions a');
        self::assertSelectorCount(2, 'main article a[href*="/historique/"]');
    }

    public function testPageUsesPedagogicalSectionAndCourseOrder(): void
    {
        $section = (new \App\Entity\Sections())->setName('Première section')->setSlug('premiere')
            ->setProgram($this->course->getSection()->getProgram())->setPosition(0);
        $this->course->getSection()->setPosition(1);
        $this->course->setPosition(1);
        $first = (new Courses())->setName('Premier test')->setSlug('premier')->setSection($section)
            ->setContentType(CourseContentType::Quiz)->setQuiz($this->course->getQuiz());
        $second = (new Courses())->setName('Deuxième test')->setSlug('deuxieme')->setSection($this->course->getSection())
            ->setPosition(0)->setContentType(CourseContentType::Quiz)->setQuiz($this->course->getQuiz());
        foreach ([$section, $first, $second] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $crawler = $this->client->request('GET', '/mes-resultats');
        self::assertResponseIsSuccessful();
        self::assertSame(['Première section', 'Quiz section'], $crawler->filter('summary h2 .results-section-name')->each(static fn ($node) => $node->text()));
        self::assertSelectorCount(2, 'details.results-section[open]');
        self::assertSame(['1 test', '2 tests'], $crawler->filter('.results-section-count')->each(static fn ($node) => $node->text()));
        self::assertSame([
            '/courses/quiz-formation/premiere/premier',
            '/courses/quiz-formation/quiz-section/deuxieme',
            '/courses/quiz-formation/quiz-section/quiz-cours',
        ], $crawler->filter('main article a')->each(static fn ($node) => $node->attr('href')));
    }

    public function testPageDoesNotDrawAnInvalidZeroTotal(): void
    {
        $attempt = $this->attempt(0);
        (new \ReflectionProperty(QuizAttempt::class, 'total'))->setValue($attempt, 0);
        $this->em()->flush();
        $this->client->request('GET', '/mes-resultats');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.results-score', 'Indisponible');
        self::assertSelectorNotExists('main canvas');
    }

    public function testHistoryChronologyDeltasSelectionAndCorrectionReturnWithoutWrites(): void
    {
        for ($i = 2; $i < 10; ++$i) {
            $this->course->getQuiz()->addQuestion(\App\Tests\Support\QuizFactory::question()->setPosition($i));
        }
        $this->em()->flush();
        $attempts = [];
        foreach ([4, 6, 5, 7, 9] as $index => $score) {
            $attempt = $this->attempt($score);
            // First two completions tie: their ids establish chronology.
            (new \ReflectionProperty(QuizAttempt::class, 'completedAt'))->setValue($attempt,
                new \DateTimeImmutable('2026-09-18 10:0'.max(0, $index - 1).':00'));
            $attempts[] = $attempt;
        }
        $this->attempt(0, false);
        $this->em()->flush();
        $before = $this->databaseState();
        $url = '/mes-resultats/historique/'.$attempts[0]->getId();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav a[href="/mes-resultats"][aria-current="page"]');
        self::assertSelectorTextContains('.results-gain', '+50 points depuis la première tentative');
        self::assertSelectorTextContains('#results-history-heading', 'Mes 5 tentatives');
        self::assertSelectorTextContains('header', 'Continuer le test');
        self::assertSelectorTextContains('#tentative-selectionnee', '90 %');
        self::assertSelectorTextContains('#tentative-selectionnee', 'Dernière tentative');
        self::assertSelectorTextContains('#tentative-selectionnee', 'Meilleur score');
        self::assertSame(['+20 points', '+20 points', '-10 points', '+20 points', '—'],
            $crawler->filter('tbody tr td:nth-child(3)')->each(static fn ($node) => $node->text()));
        $chart = json_decode($crawler->filter('canvas[data-result-chart-evolution-value]')->attr('data-symfony--ux-chartjs--chart-view-value'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([40, 60, 50, 70, 90], array_column($chart['data']['datasets'][0]['data'], 'y'));
        self::assertSame(5, count(array_unique($chart['data']['labels'])));
        self::assertSame(0, $chart['data']['datasets'][0]['tension']);
        self::assertSame(0, $chart['options']['scales']['y']['min']);
        self::assertSame(100, $chart['options']['scales']['y']['max']);
        self::assertSame('category', $chart['options']['scales']['x']['type']);
        self::assertFalse($chart['options']['animation']);
        self::assertStringNotContainsString('SECRET première', $this->client->getResponse()->getContent());
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));

        $crawler = $this->client->request('GET', $url.'?attempt='.$attempts[1]->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tr.is-selected', 'Tentative 2 · Sélectionnée');
        self::assertSelectorExists('tr.is-selected a[aria-current="true"]');
        self::assertSelectorTextContains('#tentative-selectionnee', '60 %');
        self::assertSelectorTextNotContains('#tentative-selectionnee', 'Dernière tentative');
        self::assertSame('/mes-resultats/tentatives/'.$attempts[1]->getId(), $crawler->selectLink('Revoir cette correction')->attr('href'));
        $ring = json_decode($crawler->filter('.results-ring canvas')->attr('data-symfony--ux-chartjs--chart-view-value'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([6, 4], $ring['data']['datasets'][0]['data']);
        $crawler = $this->client->clickLink('Revoir cette correction');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', '6 sur 10');
        self::assertSame('/mes-resultats/historique/'.$attempts[1]->getId().'?attempt='.$attempts[1]->getId().'#tentative-selectionnee',
            $crawler->selectLink('Retour à mon évolution et mes tentatives')->attr('href'));
        $this->client->clickLink('Retour à mon évolution et mes tentatives');
        self::assertSelectorTextContains('#tentative-selectionnee', '60 %');
        self::assertSame($before, $this->databaseState());
    }

    public function testHistoryRefusesForeignGroupsAccountsAndUnfinishedSelections(): void
    {
        $anchor = $this->attempt(1);
        $active = $this->attempt(0, false);
        $otherUser = QuizPlayerFactory::user('history-other@example.test');
        $this->em()->persist($otherUser);
        $foreign = $this->attempt(2, user: $otherUser);
        $otherCourse = (new Courses())->setName('Autre cours')->setSlug('autre-historique')->setSection($this->course->getSection())
            ->setContentType(CourseContentType::Quiz)->setQuiz($this->course->getQuiz());
        $this->em()->persist($otherCourse);
        $differentCourse = $this->attempt(2, course: $otherCourse);
        $replacement = (new Quiz())->setTitle('Remplacement');
        $this->em()->persist($replacement);
        $this->course->setQuiz($replacement);
        $differentQuiz = $this->attempt(1, snapshot: $anchor->getSnapshot());
        $before = $this->databaseState();
        foreach ([$active, $foreign, $differentCourse, $differentQuiz] as $selection) {
            $this->client->request('GET', '/mes-resultats/historique/'.$anchor->getId().'?attempt='.$selection->getId());
            self::assertResponseStatusCodeSame(404);
            self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        }
        foreach ([$foreign->getId(), $active->getId(), 999999] as $id) {
            $this->client->request('GET', '/mes-resultats/historique/'.$id);
            self::assertResponseStatusCodeSame(404);
        }
        self::assertSame($before, $this->databaseState());
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/mes-resultats/historique/'.$anchor->getId());
        self::assertResponseRedirects('/login');
    }

    public function testMalformedHistoryParametersAreNeverSilentlyIgnored(): void
    {
        $anchor = $this->attempt(1);
        foreach (['attempt=0', 'attempt=-1', 'attempt=01', 'attempt=1.5', 'attempt=', 'attempt=abc', 'attempt[]=1', 'attempt=99999999999999999999999'] as $query) {
            $this->client->request('GET', '/mes-resultats/historique/'.$anchor->getId().'?'.$query);
            self::assertResponseStatusCodeSame(404);
        }
        foreach (['0', '01', '99999999999999999999999'] as $anchorId) {
            $this->client->request('GET', '/mes-resultats/historique/'.$anchorId);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testHistoryBreaksChangedContentWithoutSkippingAnyPoints(): void
    {
        $first = $this->attempt(1);
        $snapshot = $first->getSnapshot();
        $snapshot['questions'][0]['answers'][0]['content'] = 'Autre proposition';
        $this->attempt(2, snapshot: $snapshot);
        $this->attempt(0, snapshot: $first->getSnapshot());
        $crawler = $this->client->request('GET', '/mes-resultats/historique/'.$first->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.results-gain');
        self::assertSelectorTextContains('main', 'Le contenu du test a changé');
        self::assertSame(['Contenu du test modifié', 'Contenu du test modifié', '—'],
            $crawler->filter('tbody tr td:nth-child(3)')->each(static fn ($node) => $node->text()));
        $chart = json_decode($crawler->filter('canvas[data-result-chart-evolution-value]')->attr('data-symfony--ux-chartjs--chart-view-value'), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(3, $chart['data']['datasets']);
        self::assertSame([50, 100, 0], array_map(static fn ($dataset) => $dataset['data'][0]['y'], $chart['data']['datasets']));
        self::assertSelectorCount(3, 'tbody tr');
    }

    public function testSingleZeroScoreHistoryAndExpiredRights(): void
    {
        $first = $this->attempt(0);
        $this->user->setRoles([]);
        $this->em()->flush();
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/mes-resultats/historique/'.$first->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Une première tentative enregistrée');
        self::assertSelectorTextContains('#tentative-selectionnee', '0 %');
        self::assertSelectorNotExists('.results-gain, main a[href*="/tentatives/"], main a[href*="/courses/"]');
        self::assertSame('—', $crawler->filter('tbody tr td:nth-child(3)')->text());
        $chart = json_decode($crawler->filter('canvas[data-result-chart-evolution-value]')->attr('data-symfony--ux-chartjs--chart-view-value'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([0], array_column($chart['data']['datasets'][0]['data'], 'y'));
    }

    public function testArchivedHistoryNeverCombinesNullAssociations(): void
    {
        $a = $this->attempt(1);
        $b = $this->attempt(2);
        $this->em()->remove($this->course);
        $this->em()->flush();
        $this->em()->clear();
        $this->client->request('GET', '/mes-resultats/historique/'.$a->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'tbody tr');
        self::assertSelectorTextContains('#tentative-selectionnee', '50 %');
        $this->client->request('GET', '/mes-resultats/historique/'.$a->getId().'?attempt='.$b->getId());
        self::assertResponseStatusCodeSame(404);
    }

    /** @return array<string, mixed> */
    private function databaseState(): array
    {
        $connection = $this->em()->getConnection();

        return ['attempts' => $connection->fetchAllAssociative('SELECT * FROM quiz_attempt ORDER BY id'),
            'lessons' => $connection->fetchAllAssociative('SELECT * FROM lesson ORDER BY id')];
    }
}
