<?php

namespace App\Tests\Controller\Course;

use App\Entity\Comment;
use App\Entity\Courses;
use App\Entity\Exercice;
use App\Entity\Lesson;
use App\Entity\Program;
use App\Entity\Sections;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CourseContentType;
use App\Enum\LessonStatus;
use App\Enum\SubscriptionStatus;
use App\Enum\UserAccountStatus;
use App\Tests\Support\QuizPlayerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class FreeCoursesTest extends WebTestCase
{
    private const SUMMARY = '/courses/formation-en-orthographe';
    private const READER = self::SUMMARY.'/quiz-section/lecture-gratuite';
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private Courses $free;
    private Courses $paid;
    private Courses $quiz;
    private Exercice $exercise;
    private Comment $comment;
    /** @var array{?string, ?string} */
    private array $previousUrls;

    protected function setUp(): void
    {
        $this->previousUrls = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $this->client = self::createClient();
        $this->client->disableReboot();
        // Symfony's CLI renderer dumps exception subjects even with debug=false.
        // Exercise the non-debug HTTP error rendering used by a browser instead.
        self::getContainer()->set('error_renderer', new \Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer(false));
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        self::assertTrue($this->em->getConnection()->getParams()['memory']);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        [$this->user, $this->quiz] = QuizPlayerFactory::create($this->em);
        $this->user->setRoles([]);
        $section = $this->quiz->getSection();
        $section->getProgram()->setSlug('formation-en-orthographe');
        $section->setShortDescription('SECRET description de section');
        $this->quiz->setIsFree(true)->setPosition(2);
        $this->paid = (new Courses())->setName('Cours réservé')->setSlug('cours-reserve')->setSection($section)
            ->setPosition(0)->setShortDescription('SECRET contenu réservé');
        $this->free = (new Courses())->setName('Lecture gratuite')->setSlug('lecture-gratuite')->setSection($section)
            ->setPosition(1)->setIsFree(true)->setPartialFileName('free-lot3.html.twig');
        $this->exercise = (new Exercice())->setTitle('SECRET exercice')->setInstruction('SECRET consigne')
            ->setData(['sentences' => []]);
        $this->free->setExercice($this->exercise);
        $exerciseCourse = (new Courses())->setName('Exercice réservé')->setSlug('exercice-reserve')->setSection($section)
            ->setContentType(CourseContentType::Exercise)->setIsFree(true)->setExercice($this->exercise)->setPosition(3);
        $this->comment = (new Comment())->setUser($this->user)->setCourse($this->free)->setContent('SECRET commentaire');
        $lesson = (new Lesson())->setUser($this->user)->setCourse($this->free)->setName('Lecture gratuite')->setStatus(LessonStatus::DONE);
        foreach ([$this->paid, $this->free, $this->exercise, $exerciseCourse, $this->comment, $lesson] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $root = $_SERVER['COURSE_STORAGE_DIR'];
        self::assertStringContainsString('orthogram-course-tests-', $root);
        if (!is_dir($root.'/files')) {
            mkdir($root.'/files');
        }
        file_put_contents($root.'/files/free-lot3.html.twig', '<p>CONTENU GRATUIT {{ course.name }}</p>');
        $this->em->clear();
        $this->user = $this->em->find(User::class, $this->user->getId());
        $this->free = $this->em->find(Courses::class, $this->free->getId());
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->getConnection()->close();
        }
        parent::tearDown();
        [$_ENV['DATABASE_URL'], $_SERVER['DATABASE_URL']] = $this->previousUrls;
        if (null === $_ENV['DATABASE_URL']) {
            unset($_ENV['DATABASE_URL']);
        }
        if (null === $_SERVER['DATABASE_URL']) {
            unset($_SERVER['DATABASE_URL']);
        }
    }

    private function profile(string $profile): void
    {
        if ('anonymous' === $profile) {
            $this->client->getCookieJar()->clear();

            return;
        }
        $this->user->setRoles('admin' === $profile ? ['ROLE_ADMIN'] : []);
        if (in_array($profile, ['subscriber', 'expired', 'future'], true)) {
            $subscription = (new Subscription())->setUser($this->user)->setEmail($this->user->getEmail())
                ->setStatus(SubscriptionStatus::ACTIVE)->setStartsAt(new \DateTimeImmutable('future' === $profile ? '+1 day' : '-2 days'))
                ->setEndsAt(new \DateTimeImmutable('expired' === $profile ? '-1 day' : '+2 days'));
            $this->user->addSubscription($subscription);
            $this->em->persist($subscription);
        }
        if ('inactive' === $profile) {
            $this->user->setAccountStatus(UserAccountStatus::INACTIVE);
        }
        $this->em->flush();
        $this->client->loginUser($this->user);
        if (in_array($profile, ['2fa', 'remember-me'], true)) {
            $token = '2fa' === $profile
                ? new TwoFactorToken(new UsernamePasswordToken($this->user, 'main', $this->user->getRoles()), null, 'main', ['email'])
                : new RememberMeToken($this->user, 'main');
            $session = $this->client->getSession();
            $session->set('_security_main', serialize($token));
            $session->save();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function publicProfiles(): iterable
    {
        foreach (['anonymous', 'unsubscribed', 'expired', 'future', 'remember-me'] as $profile) {
            yield $profile => [$profile];
        }
    }

    #[DataProvider('publicProfiles')]
    public function testPublicSummaryAndReaderContainNoPrivateData(string $profile): void
    {
        $this->profile($profile);
        foreach ([self::SUMMARY, self::READER] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private'));
            self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
            self::assertSelectorExists('meta[name="turbo-cache-control"][content="no-cache"]');
            $html = $this->client->getResponse()->getContent();
            self::assertStringNotContainsString('SECRET', $html);
            self::assertStringNotContainsString('data-learning-reminder-', $html);
            foreach (['#comments', '#completion-button', '[role="progressbar"]', '.completed',
                '[data-controller="quiz"]', '[data-controller="click-words"]', '[data-controller="learning-reminder"]',
                'form[name="comment_form"]', 'form[name="course_search"]'] as $selector) {
                self::assertSelectorNotExists($selector);
            }
            foreach (['desktop', 'mobile'] as $prefix) {
                $list = $this->client->getCrawler()->filter('[id^="'.$prefix.'-collapse-"]');
                self::assertSame(1, $list->count());
                self::assertSame(1, $list->filter('a[href="'.self::READER.'"]')->count());
                self::assertSame(3, $list->filter('[aria-disabled="true"]')->count());
                self::assertSame(1, $list->filter('.badge')->count());
                self::assertStringContainsString('Réservé aux abonnés', $list->text());
            }
            self::assertSelectorNotExists('a[href="'.self::SUMMARY.'/quiz-section"]');
            self::assertSelectorExists('a[href="/abonnement"]');
            self::assertSelectorTextContains('.navbar a[href="'.self::SUMMARY.'"]', 'Découvrir les cours');
            if (self::SUMMARY === $url) {
                self::assertSelectorCount(1, '.program-course-content .badge');
                self::assertSelectorTextContains('.program-course-content .badge', 'Gratuit');
            }
        }
        self::assertSelectorTextContains('#lesson-content > .badge', 'Cours gratuit');
        self::assertSelectorTextContains('#lesson-content', 'CONTENU GRATUIT Lecture gratuite');
        self::assertSelectorNotExists('#lesson-navigation a');
        self::assertSelectorTextContains('#lesson-navigation', 'Précédent : Cours réservé');
        self::assertSelectorTextContains('#lesson-navigation', 'Suivant : Quiz cours');
        self::assertSame(1, $this->em->getRepository(Lesson::class)->count([]));
        self::assertSame(1, $this->em->getRepository(Comment::class)->count([]));
    }

    #[DataProvider('publicProfiles')]
    public function testPaidAndInconsistentQuizExerciseAndPrivateSpacesRemainDenied(string $profile): void
    {
        $this->profile($profile);
        foreach ([self::SUMMARY.'/quiz-section/cours-reserve', self::SUMMARY.'/quiz-section/quiz-cours',
            self::SUMMARY.'/quiz-section/exercice-reserve', '/ma-formation', self::SUMMARY.'/quiz-section',
            '/course-search/formation-en-orthographe', '/course/details/'.$this->paid->getId(),
            '/course/details/'.$this->free->getId(),
            '/course/'.$this->quiz->getId().'/quiz', '/exercise/'.$this->exercise->getId()] as $url) {
            $this->client->request('GET', $url);
            self::assertContains($this->client->getResponse()->getStatusCode(), [302, 403], $url);
            self::assertStringNotContainsString('SECRET', $this->client->getResponse()->getContent());
        }
    }

    #[DataProvider('publicProfiles')]
    public function testFreeWithdrawalIsAppliedToNextRequest(string $profile): void
    {
        $this->profile($profile);
        $this->client->request('GET', self::READER);
        self::assertResponseIsSuccessful();
        $this->em->find(Courses::class, $this->free->getId())->setIsFree(false);
        $this->em->flush();
        $this->client->request('GET', self::READER, server: ['HTTP_IF_NONE_MATCH' => '*']);
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);
        self::assertStringNotContainsString('CONTENU GRATUIT', $this->client->getResponse()->getContent());
        $this->client->request('GET', self::SUMMARY);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="'.self::READER.'"]');
    }

    /** @return iterable<string, array{string}> */
    public static function privateProfiles(): iterable
    {
        yield 'subscriber' => ['subscriber'];
        yield 'admin' => ['admin'];
    }

    #[DataProvider('privateProfiles')]
    public function testAuthorizedUsersRetainNavigationAndPersonalIndicators(string $profile): void
    {
        $this->profile($profile);
        foreach ([self::SUMMARY, '/ma-formation'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[data-controller="learning-reminder"]');
            self::assertSelectorExists('[role="progressbar"]');
            self::assertSelectorExists('a[href="'.self::SUMMARY.'/quiz-section/cours-reserve"]');
            self::assertSelectorNotExists('.course-page .badge');
            self::assertSelectorNotExists('.navbar a[href="'.self::SUMMARY.'"]');
            self::assertSelectorExists('.navbar a[href="/ma-formation"]');
        }
        $this->client->request('GET', self::READER);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#completion-button');
        self::assertSelectorTextContains('#comments', 'SECRET commentaire');
        self::assertSelectorExists('a[rel="prev"][href="'.self::SUMMARY.'/quiz-section/cours-reserve"]');
        self::assertSelectorExists('a[rel="next"][href="'.self::SUMMARY.'/quiz-section/quiz-cours"]');
        self::assertSelectorExists('.completed');
        self::assertSelectorNotExists('.course-page .badge');
        self::assertSelectorNotExists('.navbar a[href="'.self::SUMMARY.'"]');
        $this->client->request('GET', self::SUMMARY.'/quiz-section/cours-reserve');
        self::assertResponseIsSuccessful();
    }

    /** @return iterable<string, array{string}> */
    public static function displayProfiles(): iterable
    {
        yield from self::publicProfiles();
        yield from self::privateProfiles();
    }

    #[DataProvider('displayProfiles')]
    public function testGlobalNavbarWithoutProgramContext(string $profile): void
    {
        $this->profile($profile);
        // The login controller renders no "program" variable, including for signed-in users.
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        $discovery = '.navbar a[href="'.self::SUMMARY.'"]';
        if (in_array($profile, ['subscriber', 'admin'], true)) {
            self::assertSelectorNotExists($discovery);
        } else {
            self::assertSelectorTextContains($discovery, 'Découvrir les cours');
        }
        if ('anonymous' === $profile) {
            self::assertSelectorExists('.navbar a[href="/login"]');
            self::assertSelectorNotExists('.navbar a[href="/ma-formation"]');
        } else {
            self::assertSelectorExists('.navbar a[href="/ma-formation"]');
            self::assertSelectorExists('.navbar a[href="/mes-resultats"]');
        }
    }

    public function testInactiveAndIncompleteTwoFactorCannotReadFreeCourse(): void
    {
        $this->profile('2fa');
        $this->client->request('GET', self::READER);
        self::assertResponseRedirects('/2fa');
        self::assertStringNotContainsString('CONTENU GRATUIT', $this->client->getResponse()->getContent());
        $this->user = $this->em->find(User::class, $this->user->getId());
        $this->profile('inactive');
        $this->client->request('GET', self::READER);
        self::assertResponseRedirects('/login');
        self::assertStringNotContainsString('CONTENU GRATUIT', $this->client->getResponse()->getContent());
    }

    public function testHierarchyCannotBeForged(): void
    {
        $other = (new Program())->setName('Autre formation')->setSlug('autre')->setDescription('Autre')->setPrice(0);
        $section = (new Sections())->setName('Autre section')->setSlug('autre')->setProgram($this->free->getSection()->getProgram());
        $this->em->persist($other);
        $this->em->persist($section);
        $this->em->flush();
        foreach (['/courses/autre/quiz-section/lecture-gratuite', self::SUMMARY.'/autre/lecture-gratuite'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString('CONTENU GRATUIT', $this->client->getResponse()->getContent());
        }
    }

    #[DataProvider('publicProfiles')]
    public function testInteractionsAndPlanningCannotWriteWithoutPrivateRights(string $profile): void
    {
        $this->profile($profile);
        $connection = $this->em->getConnection();
        $snapshot = static function () use ($connection): array {
            $rows = [];
            foreach ($connection->createSchemaManager()->listTableNames() as $table) {
                $rows[$table] = $connection->fetchAllAssociative('SELECT * FROM '.$connection->getDatabasePlatform()->quoteSingleIdentifier($table));
            }

            return $rows;
        };
        $before = $snapshot();
        foreach (['/course/confirmation/'.$this->free->getId(), '/course/comment/'.$this->free->getId(),
            '/comments/'.$this->comment->getId().'/edit', '/comments/'.$this->comment->getId().'/like',
            '/comments/'.$this->comment->getId().'/report', '/exercise/'.$this->exercise->getId().'/submit',
            '/course/'.$this->quiz->getId().'/quiz/start', self::SUMMARY.'/learning-reminder',
            self::SUMMARY.'/learning-reminder/disable', self::SUMMARY.'/learning-reminder/calendar/google',
            self::SUMMARY.'/learning-reminder/calendar/ics'] as $url) {
            $this->client->request('POST', $url, ['content' => 'Interdit']);
            self::assertContains($this->client->getResponse()->getStatusCode(), [302, 403], $url);
            self::assertSame($before, $snapshot());
        }
    }

    public function testNavigationKeepsOrderAcrossSectionsAndLinksAccessibleNeighbors(): void
    {
        $paid = $this->em->find(Courses::class, $this->paid->getId());
        $paid->setIsFree(true);
        $section = (new Sections())->setName('Suite')->setSlug('suite')->setPosition(1)
            ->setProgram($this->free->getSection()->getProgram());
        $last = (new Courses())->setName('Dernier cours gratuit')->setSlug('dernier')->setIsFree(true)->setSection($section);
        $this->em->persist($section);
        $this->em->persist($last);
        $this->em->flush();
        $this->client->request('GET', self::READER);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#lesson-navigation a[rel="prev"][href="'.self::SUMMARY.'/quiz-section/cours-reserve"]');
        self::assertSelectorNotExists('#lesson-navigation a[rel="next"]');
        self::assertSelectorTextContains('#lesson-navigation', 'Suivant : Quiz cours');
        $this->client->request('GET', self::SUMMARY.'/suite/dernier');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#lesson-navigation', 'Précédent : Exercice réservé');
        self::assertSelectorNotExists('#lesson-navigation a');
    }

    public function testAudioCorrectionRemainsPrivateAndLinkContentRemainsReadable(): void
    {
        $this->free->setContentType(CourseContentType::Audio)->setCorrectionText('SECRET correction audio');
        $this->em->flush();
        $this->client->request('GET', self::READER);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('SECRET', $this->client->getResponse()->getContent());
        self::assertSelectorNotExists('.course-dictation-panel');
        $this->em->find(Courses::class, $this->free->getId())->setContentType(CourseContentType::Link);
        $this->em->flush();
        $this->client->request('GET', self::READER);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#lesson-content', 'CONTENU GRATUIT');
    }
}
