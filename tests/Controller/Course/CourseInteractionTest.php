<?php

namespace App\Tests\Controller\Course;

use App\Entity\Comment;
use App\Entity\Courses;
use App\Entity\Exercice;
use App\Entity\ExerciceAttempt;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CourseContentType;
use App\Enum\SubscriptionStatus;
use App\Repository\CommentRepository;
use App\Repository\ExerciceAttemptRepository;
use App\Repository\LessonRepository;
use App\Security\Voter\CourseVoter;
use App\Services\QuizAttemptService;
use App\Tests\Support\QuizPlayerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;

final class CourseInteractionTest extends WebTestCase
{
    private const READER = '/courses/quiz-formation/quiz-section/reading';
    private KernelBrowser $client;
    private string $database;
    private array $previousUrls;
    private User $user;
    private Courses $course;
    private Courses $quiz;
    private Exercice $exercise;
    private Exercice $orphan;
    private Comment $ownComment;
    private Comment $otherComment;
    private string $lessonToken;
    private string $quizToken;
    private string $exerciseToken;
    private string $orphanToken;
    private string $formToken;
    private array $commentTokens;

    protected function setUp(): void
    {
        $this->previousUrls = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $this->database = str_replace('\\', '/', dirname(__DIR__, 3).'/var/course-interaction-'.bin2hex(random_bytes(8)).'.sqlite');
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///'.$this->database;
        $this->client = self::createClient();
        $this->client->disableReboot();
        $params = $this->em()->getConnection()->getParams();
        self::assertSame('pdo_sqlite', $params['driver']);
        self::assertSame($this->database, $params['path']);
        (new SchemaTool($this->em()))->createSchema($this->em()->getMetadataFactory()->getAllMetadata());
        [$this->user, $this->quiz] = QuizPlayerFactory::create($this->em());
        $this->user->setRoles([]);
        $subscription = (new Subscription())->setUser($this->user)->setEmail($this->user->getEmail())
            ->setStatus(SubscriptionStatus::ACTIVE)->setStartsAt(new \DateTimeImmutable('-1 day'))->setEndsAt(new \DateTimeImmutable('+1 day'));
        $this->user->addSubscription($subscription);
        $this->em()->persist($subscription);
        $this->course = (new Courses())->setName('Lecture')->setSlug('reading')->setSection($this->quiz->getSection())
            ->setContentType(CourseContentType::Twig)->setIsFree(true);
        $this->exercise = $this->exercise('Lié');
        $this->orphan = $this->exercise('Sans cours');
        $this->course->setExercice($this->exercise);
        $other = QuizPlayerFactory::user('other@example.test')->setRoles([]);
        $this->ownComment = (new Comment())->setContent('Mon commentaire')->setUser($this->user)->setCourse($this->course);
        $this->otherComment = (new Comment())->setContent('Commentaire privé')->setUser($other)->setCourse($this->course);
        foreach ([$this->course, $this->exercise, $this->orphan, $other, $this->ownComment, $this->otherComment] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->client->loginUser($this->user);
        $page = $this->client->request('GET', self::READER);
        self::assertResponseIsSuccessful();
        $this->lessonToken = $page->filter('#completion-button input[name="_token"]')->attr('value');
        $this->formToken = $page->filter('input[name="comment_form[_token]"]')->attr('value');
        $this->commentTokens = [];
        foreach (['edit' => $this->ownComment, 'like' => $this->otherComment, 'report' => $this->otherComment] as $action => $comment) {
            $this->commentTokens[$action] = 'like' === $action
                ? $page->filter('[data-like-url="/comments/'.$comment->getId().'/like"]')->attr('data-like-token')
                : $page->filter('form[action="/comments/'.$comment->getId().'/'.$action.'"] input[name="_token"]')->attr('value');
        }
        $page = $this->client->request('GET', '/courses/quiz-formation/quiz-section/quiz-cours');
        $this->quizToken = $page->filter('[data-controller="quiz"]')->attr('data-quiz-token-value');
        foreach (['exercise', 'orphan'] as $property) {
            $page = $this->client->request('GET', '/exercise/'.$this->{$property}->getId());
            self::assertResponseIsSuccessful();
            $this->{$property.'Token'} = $page->filter('[data-controller="click-words"]')->attr('data-click-words-token-value');
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->database) && is_file($this->database)) {
            unlink($this->database);
        }
        if (isset($this->previousUrls)) {
            [$_ENV['DATABASE_URL'], $_SERVER['DATABASE_URL']] = $this->previousUrls;
            if (null === $_ENV['DATABASE_URL']) {
                unset($_ENV['DATABASE_URL']);
            }
            if (null === $_SERVER['DATABASE_URL']) {
                unset($_SERVER['DATABASE_URL']);
            }
        }
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function exercise(string $title): Exercice
    {
        return (new Exercice())->setTitle($title)->setInstruction('Sélectionnez le verbe.')->setData(['sentences' => [
            ['id' => 's1', 'words' => [['id' => 'w1', 'text' => 'Chante', 'isAnswer' => true, 'explanation' => 'Un verbe.']]],
        ]]);
    }

    private function denyInteractionOnly(): void
    {
        self::$kernel->shutdown();
        self::$kernel->boot();
        $checker = new AuthorizationChecker(self::getContainer()->get('security.token_storage'), self::getContainer()->get('security.access.decision_manager'));
        $gate = $this->createMock(AuthorizationCheckerInterface::class);
        $gate->expects(self::atLeastOnce())->method('isGranted')->willReturnCallback(static fn ($attribute, $subject = null) =>
            CourseVoter::INTERACT !== $attribute && $checker->isGranted($attribute, $subject));
        self::getContainer()->set('security.authorization_checker', $gate);
        $this->client->loginUser($this->em()->find(User::class, $this->user->getId()));
        self::assertTrue($gate->isGranted(CourseVoter::VIEW, $this->course));
        self::assertFalse($gate->isGranted(CourseVoter::INTERACT, $this->course));
    }

    /** Complete table contents detect inserts, updates and deletions after a refusal. */
    private function databaseState(): array
    {
        $connection = $this->em()->getConnection();
        $state = [];
        foreach ($connection->createSchemaManager()->listTableNames() as $table) {
            $state[$table] = $connection->fetchAllAssociative('SELECT * FROM '.$connection->getDatabasePlatform()->quoteSingleIdentifier($table));
        }

        return $state;
    }

    #[DataProvider('endpoints')]
    public function testValidRequestsRequireInteractionAndCannotWriteWhenDenied(string $endpoint, bool $split): void
    {
        $payload = [];
        if (in_array($endpoint, ['answer', 'finish', 'restart'], true)) {
            $this->user = $this->em()->find(User::class, $this->user->getId());
            $this->quiz = $this->em()->find(Courses::class, $this->quiz->getId());
            $service = self::getContainer()->get(QuizAttemptService::class);
            $state = $service->mutate($this->user, $this->quiz, 'start', []);
            $payload = ['attemptId' => $state['attemptId'], 'questionId' => $state['question']['id'],
                'selectedIds' => [$state['question']['answers'][0]['id']]];
            if ('answer' !== $endpoint) {
                while (isset($state['question'])) {
                    $state = $service->mutate($this->user, $this->quiz, 'answer', ['attemptId' => $state['attemptId'],
                        'questionId' => $state['question']['id'], 'selectedIds' => [$state['question']['answers'][0]['id']]]);
                }
                if ('restart' === $endpoint) {
                    $service->mutate($this->user, $this->quiz, 'finish', ['attemptId' => $state['attemptId']]);
                }
            }
        }
        if ($split) {
            $this->denyInteractionOnly();
        } else {
            $user = $this->em()->find(User::class, $this->user->getId());
            foreach ($user->getSubscriptions() as $subscription) {
                $subscription->setStatus(SubscriptionStatus::CANCELLED);
            }
            $this->em()->flush();
            $this->client->loginUser($user);
        }
        $before = $this->databaseState();
        switch ($endpoint) {
            case 'confirmation':
                $this->client->request('POST', '/course/confirmation/'.$this->course->getId(), ['_token' => $this->lessonToken]);
                break;
            case 'comment':
                // A reply to someone else is valid even with an existing own root comment.
                $this->client->request('POST', '/course/comment/'.$this->course->getId(), ['comment_form' => [
                    'content' => 'Une réponse valide', 'parent' => (string) $this->otherComment->getId(), '_token' => $this->formToken,
                ]], [], ['HTTP_ORIGIN' => 'http://localhost']);
                break;
            case 'edit': case 'like': case 'report':
                $comment = 'edit' === $endpoint ? $this->ownComment : $this->otherComment;
                $this->client->request('POST', '/comments/'.$comment->getId().'/'.$endpoint,
                    ['_token' => $this->commentTokens[$endpoint], 'content' => 'Modification valide']);
                break;
            case 'exercise-show': case 'orphan-show':
                $exercise = 'orphan-show' === $endpoint ? $this->orphan : $this->exercise;
                $this->client->request('GET', '/exercise/'.$exercise->getId());
                break;
            case 'exercise-submit': case 'orphan-submit':
                $orphan = 'orphan-submit' === $endpoint;
                $this->submitExercise($orphan ? $this->orphan : $this->exercise, $orphan ? $this->orphanToken : $this->exerciseToken);
                break;
            case 'state':
                $this->client->request('GET', '/course/'.$this->quiz->getId().'/quiz');
                break;
            default:
                $this->client->request('POST', '/course/'.$this->quiz->getId().'/quiz/'.$endpoint, [], [],
                    ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->quizToken], json_encode((object) $payload, JSON_THROW_ON_ERROR));
        }
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('CSRF', $this->client->getResponse()->getContent());
        self::assertSame($before, $this->databaseState());
    }

    public static function endpoints(): iterable
    {
        foreach (['confirmation', 'comment', 'edit', 'like', 'report', 'exercise-show', 'orphan-show',
            'exercise-submit', 'orphan-submit', 'state', 'start', 'answer', 'finish', 'restart'] as $endpoint) {
            foreach ([false, true] as $split) {
                yield $endpoint.($split ? '-view-only' : '-no-subscription') => [$endpoint, $split];
            }
        }
    }

    private function submitExercise(Exercice $exercise, ?string $token): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
            $headers['HTTP_X_CSRF_TOKEN'] = $token;
        }
        $this->client->request('POST', '/exercise/'.$exercise->getId().'/submit', [], [], $headers, '{"selected":["w1"]}');
    }

    #[DataProvider('exerciseProfiles')]
    public function testExerciseCsrfAndAuthorizedPath(string $profile, bool $orphan): void
    {
        $user = $this->em()->find(User::class, $this->user->getId());
        $user->setRoles('admin' === $profile ? ['ROLE_ADMIN'] : []);
        foreach ($user->getSubscriptions() as $subscription) {
            $subscription->setStatus('admin' === $profile ? SubscriptionStatus::CANCELLED : SubscriptionStatus::ACTIVE)
                ->setIsLifetime('lifetime' === $profile);
        }
        $this->em()->flush();
        $this->client->loginUser($user);
        $exercise = $orphan ? $this->orphan : $this->exercise;
        $token = $orphan ? $this->orphanToken : $this->exerciseToken;
        $before = $this->databaseState();
        foreach ([null, 'invalid', $orphan ? $this->exerciseToken : $this->orphanToken] as $badToken) {
            $this->submitExercise($exercise, $badToken);
            self::assertResponseStatusCodeSame(403);
            self::assertStringContainsString('CSRF', $this->client->getResponse()->getContent());
            self::assertSame($before, $this->databaseState());
        }
        $this->submitExercise($exercise, $token);
        self::assertResponseIsSuccessful();
        $result = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(100, $result['percentage']);
        self::assertSame(1, $this->em()->getRepository(ExerciceAttempt::class)->count([]));
        $this->client->request('GET', '/exercise/'.$exercise->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.exercise-last-attempt', '100 %');
    }

    public static function exerciseProfiles(): iterable
    {
        foreach (['admin', 'active', 'lifetime'] as $profile) {
            foreach ([false, true] as $orphan) {
                yield $profile.($orphan ? '-orphan' : '-linked') => [$profile, $orphan];
            }
        }
    }

    #[DataProvider('readerTypes')]
    public function testReaderWithoutInteractionLoadsNoPrivateData(string $type): void
    {
        $course = $this->em()->find(Courses::class, $this->course->getId());
        $course->setContentType(CourseContentType::from($type));
        if ('quiz' === $type) {
            $course->setQuiz($this->em()->find(Courses::class, $this->quiz->getId())->getQuiz());
        }
        $this->em()->flush();
        $this->denyInteractionOnly();
        foreach ([LessonRepository::class => ['findOneByUserAndCourse', 'countDoneByUserAndProgram', 'findDoneCourseIdsByUserAndProgram'],
            CommentRepository::class => ['findRootByUserAndCourse', 'findRootCommentsByCourse', 'countByCourse'],
            ExerciceAttemptRepository::class => ['findLatestByUserAndExercice']] as $class => $methods) {
            $repository = $this->createMock($class);
            foreach ($methods as $method) {
                $repository->expects(self::never())->method($method);
            }
            self::getContainer()->set($class, $repository);
        }
        $this->client->request('GET', self::READER);
        self::assertResponseIsSuccessful();
        foreach (['#comments', '#completion-button', '[role="progressbar"]', '[data-controller="quiz"]', '[data-controller="click-words"]', 'form[name="comment_form"]'] as $selector) {
            self::assertSelectorNotExists($selector);
        }
        self::assertStringNotContainsString('Commentaire privé', $this->client->getResponse()->getContent());
    }

    public static function readerTypes(): iterable
    {
        foreach (['twig', 'exercise', 'quiz'] as $type) {
            yield $type => [$type];
        }
    }

    public function testAuthorizedCommentsKeepCsrfOwnershipAndModerationRules(): void
    {
        $before = $this->databaseState();
        $this->client->request('POST', '/comments/'.$this->ownComment->getId().'/edit', ['_token' => 'bad', 'content' => 'Refusé']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->databaseState());
        $this->client->request('POST', '/comments/'.$this->ownComment->getId().'/edit',
            ['_token' => $this->commentTokens['edit'], 'content' => 'Texte modifié']);
        self::assertResponseRedirects();
        self::assertSame('Texte modifié', $this->em()->find(Comment::class, $this->ownComment->getId())->getContent());

        $this->client->request('POST', '/course/comment/'.$this->course->getId(), ['comment_form' => [
            'content' => 'Réponse publiée', 'parent' => (string) $this->otherComment->getId(), '_token' => $this->formToken,
        ]], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects();
        self::assertSame(3, $this->em()->getRepository(Comment::class)->count([]));

        $this->client->request('POST', '/comments/'.$this->otherComment->getId().'/like', ['_token' => $this->commentTokens['like']]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->em()->getRepository(\App\Entity\CommentLike::class)->count([]));

        $other = $this->em()->find(Comment::class, $this->otherComment->getId());
        $other->setIsHidden(true);
        $this->em()->flush();
        $before = $this->databaseState();
        $this->client->request('POST', '/comments/'.$other->getId().'/like', ['_token' => $this->commentTokens['like']]);
        self::assertResponseStatusCodeSame(410);
        self::assertSame($before, $this->databaseState());
        $this->client->request('POST', '/comments/'.$other->getId().'/edit', ['_token' => $this->commentTokens['edit'], 'content' => 'Interdit']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->databaseState());
    }

    public function testAdministrativeCommentDeletionRemainsRestricted(): void
    {
        $user = $this->em()->find(User::class, $this->user->getId());
        $user->setRoles(['ROLE_ADMIN']);
        $this->em()->flush();
        $this->client->loginUser($user);
        $page = $this->client->request('GET', self::READER);
        $url = '/comments/'.$this->ownComment->getId().'/delete';
        $token = $page->filter('form[action="'.$url.'"] input')->attr('value');
        $user = $this->em()->find(User::class, $this->user->getId());
        $user->setRoles([]);
        $this->em()->flush();
        $this->client->loginUser($user);
        $before = $this->databaseState();
        $this->client->request('POST', $url, ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->databaseState());
        $user = $this->em()->find(User::class, $this->user->getId());
        $user->setRoles(['ROLE_ADMIN']);
        $this->em()->flush();
        $this->client->loginUser($user);
        $this->client->request('POST', $url, ['_token' => $token]);
        self::assertResponseRedirects();
        self::assertSame(1, $this->em()->getRepository(Comment::class)->count([]));
    }

    #[DataProvider('authenticationProfiles')]
    public function testHttpAuthenticationStateProtectsReaderAndOrphanExercise(string $state, bool $admin): void
    {
        $user = $this->em()->find(User::class, $this->user->getId());
        $user->setRoles($admin ? ['ROLE_ADMIN'] : []);
        if ('inactive' === $state) {
            $user->setAccountStatus(\App\Enum\UserAccountStatus::INACTIVE);
        }
        $this->em()->flush();
        $this->client->loginUser($user);
        $authenticated = new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user, 'main', $user->getRoles());
        $token = match ($state) {
            'two-factor' => new \Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorToken($authenticated, null, 'main', ['email']),
            'remember-me' => new \Symfony\Component\Security\Core\Authentication\Token\RememberMeToken($user, 'main'),
            default => $authenticated,
        };
        $session = $this->client->getSession();
        $session->set('_security_main', serialize($token));
        $session->save();
        $before = $this->databaseState();
        foreach ([self::READER, '/exercise/'.$this->orphan->getId()] as $url) {
            $this->client->request('GET', $url);
            if ('two-factor' === $state) {
                self::assertResponseRedirects('/2fa');
            } elseif ('inactive' === $state) {
                self::assertResponseRedirects('/login');
            } else {
                self::assertResponseIsSuccessful();
            }
        }
        if ('remember-me' !== $state) {
            $this->submitExercise($this->orphan, $this->orphanToken);
            if ('two-factor' === $state) {
                self::assertResponseRedirects('/2fa');
            } else {
                self::assertResponseRedirects('/login');
            }
        }
        self::assertSame($before, $this->databaseState());
    }

    public static function authenticationProfiles(): iterable
    {
        foreach (['inactive', 'two-factor', 'remember-me'] as $state) {
            foreach ([false, true] as $admin) {
                yield $state.($admin ? '-admin' : '-subscriber') => [$state, $admin];
            }
        }
    }
}
