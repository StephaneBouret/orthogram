<?php

namespace App\Tests\Controller\Course;

use App\Entity\Courses;
use App\Entity\Exercice;
use App\Entity\Lesson;
use App\Entity\QuizAttempt;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CourseContentType;
use App\Enum\LessonStatus;
use App\Enum\SubscriptionStatus;
use App\Enum\UserAccountStatus;
use App\Services\Courses\CourseBookmarkService;
use App\Services\UserProfileService;
use App\Tests\Support\QuizPlayerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class CourseBookmarkTest extends WebTestCase
{
    private const READER = '/courses/formation-en-orthographe/quiz-section/';
    private KernelBrowser $client;
    private string $database;
    private int $userId;
    private int $otherId;
    private int $quizId;
    private int $readingId;
    /** @var array{?string, ?string} */
    private array $previousUrls;

    protected function setUp(): void
    {
        $this->previousUrls = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $this->database = str_replace('\\', '/', dirname(__DIR__, 3).'/var/bookmark-'.bin2hex(random_bytes(8)).'.sqlite');
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///'.$this->database;
        $this->client = self::createClient();
        self::assertSame('pdo_sqlite', $this->em()->getConnection()->getParams()['driver']);
        self::assertSame($this->database, $this->em()->getConnection()->getParams()['path']);
        (new SchemaTool($this->em()))->createSchema($this->em()->getMetadataFactory()->getAllMetadata());
        [$user, $quiz] = QuizPlayerFactory::create($this->em());
        $quiz->getSection()->getProgram()->setSlug('formation-en-orthographe');
        $reading = (new Courses())->setName('Lecture terminée')->setSlug('lecture')->setIsFree(true)->setSection($quiz->getSection());
        $other = QuizPlayerFactory::user('bookmark-other@example.test');
        $lesson = (new Lesson())->setUser($user)->setCourse($reading)->setName('Lecture')->setStatus(LessonStatus::DONE);
        foreach ([$reading, $other, $lesson] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->userId = $user->getId();
        $this->otherId = $other->getId();
        $this->quizId = $quiz->getId();
        $this->readingId = $reading->getId();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->database) && is_file($this->database)) {
            unlink($this->database);
        }
        [$_ENV['DATABASE_URL'], $_SERVER['DATABASE_URL']] = $this->previousUrls;
        if (null === $_ENV['DATABASE_URL']) {
            unset($_ENV['DATABASE_URL']);
        }
        if (null === $_SERVER['DATABASE_URL']) {
            unset($_SERVER['DATABASE_URL']);
        }
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function bookmark(?int $userId = null): ?int
    {
        $value = $this->em()->getConnection()->fetchOne('SELECT last_visited_course_id FROM user WHERE id = ?', [$userId ?? $this->userId]);

        return null === $value ? null : (int) $value;
    }

    private function visit(string $slug): string
    {
        $crawler = $this->client->request('GET', self::READER.$slug);
        self::assertResponseIsSuccessful();
        $reader = $crawler->filter('[data-controller="course-bookmark"]');
        $token = $reader->attr('data-course-bookmark-token-value');
        $this->client->request('POST', $reader->attr('data-course-bookmark-url-value'), server: [
            'HTTP_X_CSRF_TOKEN' => $token, 'HTTP_X_COURSE_BOOKMARK_VERSION' => $reader->attr('data-course-bookmark-version-value'),
        ]);
        self::assertResponseStatusCodeSame(204);
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));

        return $token;
    }

    public function testReplacementResultsAndNewSessionPreserveBookmarkWithoutProgressChanges(): void
    {
        $this->client->request('GET', '/ma-formation');
        self::assertSelectorNotExists('#resume-course-title');
        $this->visit('lecture');
        self::assertSame($this->readingId, $this->bookmark());
        $this->visit('quiz-cours');
        self::assertSame($this->quizId, $this->bookmark());
        self::assertSame(0, $this->em()->getRepository(QuizAttempt::class)->count([]));
        $quiz = $this->em()->find(Courses::class, $this->quizId);
        $attempt = new QuizAttempt($this->em()->find(User::class, $this->userId), $quiz, $quiz->getQuiz(), ['version' => 1, 'title' => 'Résultat', 'questions' => []]);
        $attempt->complete();
        $this->em()->persist($attempt);
        $this->em()->flush();
        $before = $this->em()->getConnection()->fetchAllAssociative('SELECT * FROM quiz_attempt');
        $this->visit('quiz-cours'); // Reopening a completed quiz must preserve its attempt.
        $this->visit('lecture'); // A completed course is a valid bookmark.
        foreach (['/mes-resultats', '/mes-resultats/historique/'.$attempt->getId(), '/mes-resultats/tentatives/'.$attempt->getId()] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('a[href="'.self::READER.'lecture"][data-turbo-prefetch="false"]', 'Reprendre mon cours');
            self::assertSelectorExists('meta[name="turbo-cache-control"][content="no-cache"]');
            self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private'));
            self::assertSame($this->readingId, $this->bookmark());
        }
        self::assertSame($before, $this->em()->getConnection()->fetchAllAssociative('SELECT * FROM quiz_attempt'));
        self::assertSame('done', $this->em()->getConnection()->fetchOne('SELECT status FROM lesson'));
        $this->client->getCookieJar()->clear();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->loginUser($this->em()->find(User::class, $this->userId));
        $this->client->request('GET', '/ma-formation');
        self::assertSelectorTextContains('#resume-course-title', 'Reprendre ma formation');
        self::assertSelectorTextContains('section[aria-labelledby="resume-course-title"]', 'Lecture terminée');
        self::assertSelectorTextContains('section[aria-labelledby="resume-course-title"] a', 'Reprendre ce cours');
        self::assertSame($this->readingId, $this->bookmark());
    }

    public function testAccountsAndCsrfAreIsolated(): void
    {
        $token = $this->visit('lecture');
        $this->client->loginUser($this->em()->find(User::class, $this->otherId));
        $this->client->request('GET', '/ma-formation');
        self::assertSelectorNotExists('#resume-course-title');
        $this->client->request('POST', '/course/'.$this->readingId.'/bookmark', ['user' => $this->userId], server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->bookmark($this->otherId));
        $this->visit('quiz-cours');
        self::assertSame($this->quizId, $this->bookmark($this->otherId));
        self::assertSame($this->readingId, $this->bookmark());
        $this->client->request('POST', '/course/'.$this->readingId.'/bookmark', server: ['HTTP_X_CSRF_TOKEN' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function profiles(): iterable
    {
        foreach (['admin', 'subscriber', 'remember-me', 'anonymous', 'inactive', 'unsubscribed', 'expired', 'future', '2fa'] as $profile) {
            yield $profile => [$profile, in_array($profile, ['admin', 'subscriber', 'remember-me'], true)];
        }
    }

    #[DataProvider('profiles')]
    public function testEligibilityIsRecheckedForWritingAndResume(string $profile, bool $allowed): void
    {
        $token = $this->visit('lecture');
        $user = $this->em()->find(User::class, $this->userId);
        $user->setRoles('admin' === $profile || 'inactive' === $profile || '2fa' === $profile ? ['ROLE_ADMIN'] : []);
        if (in_array($profile, ['subscriber', 'remember-me', 'expired', 'future'], true)) {
            $subscription = (new Subscription())->setUser($user)->setEmail($user->getEmail())->setStatus(SubscriptionStatus::ACTIVE)
                ->setStartsAt(new \DateTimeImmutable('future' === $profile ? '+1 day' : '-2 days'))
                ->setEndsAt(new \DateTimeImmutable('expired' === $profile ? '-1 day' : '+2 days'));
            $user->addSubscription($subscription);
            $this->em()->persist($subscription);
        }
        if ('inactive' === $profile) {
            $user->setAccountStatus(UserAccountStatus::INACTIVE);
        }
        $this->em()->flush();
        $this->client->loginUser($user);
        if ('anonymous' === $profile) {
            $this->client->getCookieJar()->clear();
        } elseif (in_array($profile, ['2fa', 'remember-me'], true)) {
            $auth = '2fa' === $profile
                ? new TwoFactorToken(new UsernamePasswordToken($user, 'main', $user->getRoles()), null, 'main', ['email'])
                : new RememberMeToken($user, 'main');
            $session = $this->client->getSession();
            $session->set('_security_main', serialize($auth));
            $session->save();
        }
        // The old token still exists; loss of eligibility must be sufficient to deny it.
        $this->client->request('POST', '/course/'.$this->readingId.'/bookmark', server: ['HTTP_X_CSRF_TOKEN' => $token, 'HTTP_X_COURSE_BOOKMARK_VERSION' => '1']);
        if ($allowed) {
            self::assertResponseStatusCodeSame(204);
            $this->visit('quiz-cours');
            self::assertSame($this->quizId, $this->bookmark());
        } else {
            self::assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);
            self::assertSame($this->readingId, $this->bookmark());
        }
        $this->client->request('GET', '/courses/formation-en-orthographe');
        if ('2fa' === $profile) {
            self::assertResponseRedirects('http://localhost/2fa');

            return;
        }
        self::assertResponseIsSuccessful();
        if ($allowed) {
            self::assertSelectorExists('#resume-course-title');
        } else {
            self::assertSelectorNotExists('#resume-course-title');
            $this->client->request('GET', self::READER.'quiz-cours');
            self::assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);
        }
    }

    public function testGetPrefetchMissingPageAndRenderFailureNeverWrite(): void
    {
        $this->visit('lecture');
        foreach ([[], ['HTTP_X_SEC_PURPOSE' => 'prefetch'], ['HTTP_SEC_PURPOSE' => 'prefetch;prerender']] as $headers) {
            $this->client->request('GET', self::READER.'quiz-cours', server: $headers);
            self::assertResponseIsSuccessful();
            self::assertSame($this->readingId, $this->bookmark());
        }
        $this->client->request('GET', self::READER.'absent');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/course/'.$this->quizId.'/bookmark');
        self::assertResponseStatusCodeSame(405);
        $course = $this->em()->find(Courses::class, $this->quizId);
        $course->setContentType(CourseContentType::Twig)->setPartialFileName('bookmark-broken.html.twig');
        $this->em()->flush();
        $root = $_SERVER['COURSE_STORAGE_DIR'];
        if (!is_dir($root.'/files')) {
            mkdir($root.'/files');
        }
        file_put_contents($root.'/files/bookmark-broken.html.twig', '{% invalid_tag %}');
        $this->client->request('GET', self::READER.'quiz-cours');
        self::assertResponseStatusCodeSame(500);
        self::assertSame($this->readingId, $this->bookmark());
    }

    public function testCurrentNamesAndSlugsAndDeletedCourse(): void
    {
        $this->visit('quiz-cours');
        $course = $this->em()->find(Courses::class, $this->quizId);
        $course->setName('Titre actuel')->setSlug('nouveau-cours');
        $course->getSection()->setName('Section actuelle')->setSlug('nouvelle-section');
        $course->getSection()->getProgram()->setSlug('nouveau-programme');
        $this->em()->flush();
        $this->client->request('GET', '/courses/nouveau-programme');
        self::assertSelectorTextContains('section[aria-labelledby="resume-course-title"]', 'Section actuelle');
        self::assertSelectorTextContains('section[aria-labelledby="resume-course-title"]', 'Titre actuel');
        self::assertSelectorExists('a[href="/courses/nouveau-programme/nouvelle-section/nouveau-cours"]');
        $this->em()->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        $this->em()->remove($this->em()->find(Courses::class, $this->quizId));
        $this->em()->flush();
        $this->client->request('GET', '/mes-resultats');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Reprendre mon cours', $this->client->getResponse()->getContent());
        self::assertNull($this->bookmark());
        self::assertSame(2, $this->em()->getRepository(User::class)->count([]));
    }

    public function testInvalidRelationsAndAnonymization(): void
    {
        $this->visit('lecture');
        $course = $this->em()->find(Courses::class, $this->readingId);
        $service = self::getContainer()->get(CourseBookmarkService::class);
        $section = $course->getSection();
        $course->setSection(null);
        self::assertFalse($service->canUse($course));
        $course->setSection($section);
        $program = $section->getProgram();
        $section->setProgram(null);
        self::assertFalse($service->canUse($course));
        $section->setProgram($program);
        $course->setSlug('');
        self::assertFalse($service->canUse($course));
        $course->setSlug('lecture');
        self::getContainer()->get(UserProfileService::class)->deleteAccount($this->em()->find(User::class, $this->userId));
        self::assertNull($this->bookmark());
        self::assertNotNull($this->em()->find(Courses::class, $this->readingId));
    }

    public function testExerciseConsultationDoesNotStartAttempt(): void
    {
        $course = $this->em()->find(Courses::class, $this->readingId);
        $exercise = (new Exercice())->setTitle('Exercice')->setInstruction('Consigne')->setData(['sentences' => []]);
        $this->em()->persist($exercise);
        $course->setContentType(CourseContentType::Exercise)->setExercice($exercise);
        $this->em()->flush();
        $this->visit('lecture');
        self::assertSame($this->readingId, $this->bookmark());
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM exercice_attempt'));
        self::assertSame('done', $this->em()->getConnection()->fetchOne('SELECT status FROM lesson'));
    }

    public function testExpiredSubscriptionHidesProtectedBookmarkAndDeniesOldLink(): void
    {
        $user = $this->em()->find(User::class, $this->userId)->setRoles([]);
        $subscription = (new Subscription())->setUser($user)->setEmail($user->getEmail())->setStatus(SubscriptionStatus::ACTIVE)
            ->setStartsAt(new \DateTimeImmutable('-2 days'))->setEndsAt(new \DateTimeImmutable('+1 day'));
        $user->addSubscription($subscription);
        $this->em()->persist($subscription);
        $this->em()->flush();
        $this->client->loginUser($user);
        $token = $this->visit('quiz-cours');
        $this->em()->find(Subscription::class, $subscription->getId())->setEndsAt(new \DateTimeImmutable('-1 day'));
        $this->em()->flush();
        $this->client->request('GET', '/mes-resultats');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Reprendre mon cours', $this->client->getResponse()->getContent());
        $this->client->request('POST', '/course/'.$this->quizId.'/bookmark', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', self::READER.'quiz-cours');
        self::assertResponseStatusCodeSame(403);
        self::assertSame($this->quizId, $this->bookmark());
    }

    public function testBrokenDatabaseRelationsDoNotProduceBrokenResumeLinks(): void
    {
        $this->visit('quiz-cours');
        $connection = $this->em()->getConnection();
        $sectionId = $connection->fetchOne('SELECT section_id FROM courses WHERE id = ?', [$this->quizId]);
        // SQLite permits simulating legacy corruption; MySQL's foreign keys
        // normally prevent these states. Read paths must still tolerate them.
        $connection->executeStatement('UPDATE courses SET section_id = 999999 WHERE id = ?', [$this->quizId]);
        $this->client->request('GET', '/ma-formation');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#resume-course-title');
        $connection = $this->em()->getConnection();
        $connection->executeStatement('UPDATE courses SET section_id = ? WHERE id = ?', [$sectionId, $this->quizId]);
        $connection->executeStatement('UPDATE sections SET program_id = 999999 WHERE id = ?', [$sectionId]);
        $this->client->request('GET', '/ma-formation');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#resume-course-title');
    }

    /** @return array{url: string, token: string, version: string} */
    private function prepareVisit(string $slug): array
    {
        $crawler = $this->client->request('GET', self::READER.$slug);
        self::assertResponseIsSuccessful();
        $reader = $crawler->filter('[data-controller="course-bookmark"]');

        return ['url' => $reader->attr('data-course-bookmark-url-value'),
            'token' => $reader->attr('data-course-bookmark-token-value'),
            'version' => $reader->attr('data-course-bookmark-version-value')];
    }

    /** @param array{url: string, token: string, version: string} $visit */
    private function writeVisit(array $visit, int $expectedStatus, string $expectedVersion): void
    {
        $this->client->request('POST', $visit['url'], server: [
            'HTTP_X_CSRF_TOKEN' => $visit['token'], 'HTTP_X_COURSE_BOOKMARK_VERSION' => $visit['version'],
        ]);
        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertResponseHeaderSame('X-Course-Bookmark-Version', $expectedVersion);
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }

    public function testOldAProcessedAfterBIsRejectedAtomically(): void
    {
        $a = $this->prepareVisit('lecture');
        $b = $this->prepareVisit('quiz-cours');
        // These GETs are both completed before either write. Delay A's server
        // processing (not its response) until B has actually committed.
        $this->writeVisit($b, 204, '1');
        $this->writeVisit($a, 409, '1');
        self::assertSame($this->quizId, $this->bookmark());
        $this->client->request('GET', '/mes-resultats');
        self::assertSelectorExists('[data-course-resume][data-bookmark-version="1"] a[href="'.self::READER.'quiz-cours"]');
    }

    public function testLatestBMayRetryAfterACommittedAndReplayCannotUndoIt(): void
    {
        $a = $this->prepareVisit('lecture');
        $b = $this->prepareVisit('quiz-cours');
        $this->writeVisit($a, 204, '1');
        $this->writeVisit($b, 409, '1');
        $b['version'] = '1';
        $this->writeVisit($b, 204, '2');
        $this->writeVisit($a, 409, '2');
        $this->writeVisit($b, 409, '2');
        self::assertSame($this->quizId, $this->bookmark());
    }

    public function testMissingMalformedAndFutureVersionsNeverWrite(): void
    {
        $visit = $this->prepareVisit('lecture');
        foreach (['', '-1', '1.5', '01', '99999999999999999999', '2147483647'] as $version) {
            $this->client->request('POST', $visit['url'], server: ['HTTP_X_CSRF_TOKEN' => $visit['token'], 'HTTP_X_COURSE_BOOKMARK_VERSION' => $version]);
            self::assertResponseStatusCodeSame(400);
            self::assertNull($this->bookmark());
        }
        $visit['version'] = '50';
        $this->writeVisit($visit, 409, '0');
        self::assertNull($this->bookmark());
    }
}
