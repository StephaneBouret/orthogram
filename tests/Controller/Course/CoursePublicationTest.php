<?php

namespace App\Tests\Controller\Course;

use App\Entity\Comment;
use App\Entity\Courses;
use App\Entity\Exercice;
use App\Entity\ExerciceAttempt;
use App\Entity\Lesson;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CourseContentType;
use App\Enum\LessonStatus;
use App\Enum\SubscriptionStatus;
use App\Repository\CoursesRepository;
use App\Services\Courses\SectionCompletionService;
use App\Services\Courses\SectionDurationService;
use App\Services\Dictation\DictationCourseProgress;
use App\Tests\Support\QuizPlayerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CoursePublicationTest extends WebTestCase
{
    private const SUMMARY = '/courses/formation-en-orthographe';
    private const SECTION = self::SUMMARY.'/quiz-section';
    private KernelBrowser $client;
    private string $database;
    private int $userId;
    private int $quizId;
    private int $readingId;
    private int $exerciseId;
    private int $exerciseCourseId;
    /** @var array{?string, ?string} */
    private array $previousUrls;

    protected function setUp(): void
    {
        $this->previousUrls = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $this->database = str_replace('\\', '/', dirname(__DIR__, 3).'/var/publication-'.bin2hex(random_bytes(8)).'.sqlite');
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///'.$this->database;
        $this->client = self::createClient();
        $connection = $this->em()->getConnection();
        self::assertSame('pdo_sqlite', $connection->getParams()['driver']);
        self::assertSame($this->database, $connection->getParams()['path']);
        self::assertSame($this->database, str_replace('\\', '/', $connection->fetchAllAssociative('PRAGMA database_list')[0]['file']));
        (new SchemaTool($this->em()))->createSchema($this->em()->getMetadataFactory()->getAllMetadata());
        [$user, $quiz] = QuizPlayerFactory::create($this->em());
        $user->setRoles(['ROLE_USER']);
        $subscription = (new Subscription())->setEmail($user->getEmail())->setStatus(SubscriptionStatus::ACTIVE)->setIsLifetime(true);
        $user->addSubscription($subscription);
        $quiz->getSection()->getProgram()->setSlug('formation-en-orthographe');
        $quiz->setPosition(0)->setDurationMinutes(5);
        $reading = (new Courses())->setName('Lecture publiée')->setSlug('lecture')->setSection($quiz->getSection())
            ->setPosition(2)->setIsFree(true)->setDurationMinutes(7);
        $exercise = (new Exercice())->setTitle('Exercice suspendu')->setInstruction('Choisissez le verbe.')->setSentences([
            ['id' => 's1', 'words' => [['id' => 'yes', 'text' => 'Chante', 'isAnswer' => true]]],
        ]);
        $exerciseCourse = (new Courses())->setName('Exercice suspendu')->setSlug('exercice')->setSection($quiz->getSection())
            ->setPosition(1)->setDurationMinutes(11)->setContentType(CourseContentType::Exercise)->setExercice($exercise);
        foreach ([$subscription, $reading, $exercise, $exerciseCourse] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->userId = $user->getId();
        $this->quizId = $quiz->getId();
        $this->readingId = $reading->getId();
        $this->exerciseId = $exercise->getId();
        $this->exerciseCourseId = $exerciseCourse->getId();
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

    private function publish(int $id, bool $published): void
    {
        $this->em()->find(Courses::class, $id)->setIsPublished($published);
        $this->em()->flush();
    }

    /** @return array<string, mixed> */
    private function submitExercise(string $token, ?int $courseId): array
    {
        $payload = ['selected' => ['yes']];
        if (null !== $courseId) {
            $payload['courseId'] = $courseId;
        }
        $this->client->jsonRequest('POST', '/exercise/'.$this->exerciseId.'/submit', $payload, ['HTTP_X_CSRF_TOKEN' => $token]);

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function exerciseToken(): string
    {
        $page = $this->client->request('GET', self::SECTION.'/exercice');
        self::assertResponseIsSuccessful();

        return $page->filter('[data-controller="click-words"]')->attr('data-click-words-token-value');
    }

    public function testListsSearchNavigationProgressAndHistoryRoundTrip(): void
    {
        self::assertTrue((new Courses())->isPublished());
        $token = $this->exerciseToken();
        $result = $this->submitExercise($token, $this->exerciseCourseId);
        self::assertResponseIsSuccessful();
        self::assertTrue($result['courseCompleted']);
        $before = $this->history();
        $this->publish($this->exerciseCourseId, false);
        foreach ([self::SUMMARY, self::SECTION, self::SECTION.'/quiz-cours', self::SECTION.'/lecture'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('a[href="'.self::SECTION.'/exercice"]');
            self::assertStringNotContainsString('Exercice suspendu', $this->client->getResponse()->getContent());
            self::assertSelectorExists('[role="progressbar"][aria-valuenow="0"][aria-valuemax="2"]');
        }
        $this->client->request('GET', self::SECTION.'/quiz-cours');
        self::assertSelectorExists('a[rel="next"][href="'.self::SECTION.'/lecture"]');
        $this->client->request('GET', self::SECTION.'/lecture');
        self::assertSelectorExists('a[rel="prev"][href="'.self::SECTION.'/quiz-cours"]');
        $this->client->request('GET', '/autocomplete/courses_autocomplete_field', ['query' => 'Exercice']);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Exercice suspendu', $this->client->getResponse()->getContent());

        $course = $this->em()->find(Courses::class, $this->exerciseCourseId);
        $section = $course->getSection();
        $program = $section->getProgram();
        $courses = $this->em()->getRepository(Courses::class);
        self::assertInstanceOf(CoursesRepository::class, $courses);
        self::assertSame(2, $courses->countNumberCoursesBySection($section));
        self::assertSame(2, $courses->countCoursesBySections($program)[$section->getId()]);
        self::assertCount(3, $courses->findOrderedBySectionExcluding($section));
        self::assertCount(3, $section->getCourses());
        self::assertSame(12, (new SectionDurationService())->calculateTotalDuration([$section])[$section->getId()]);
        self::assertSame($before, $this->history());

        $this->publish($this->exerciseCourseId, true);
        $this->client->request('GET', self::SECTION.'/exercice');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[role="progressbar"][aria-valuenow="1"][aria-valuemax="3"]');
        self::assertSame($before, $this->history());
        self::assertSame(0, $this->em()->getRepository(Lesson::class)->count(['course' => $this->readingId]));
    }

    public function testStaleExercisePageCannotSubmitWithOrWithoutCourseId(): void
    {
        $token = $this->exerciseToken();
        $this->publish($this->exerciseCourseId, false);
        foreach ([self::SECTION.'/exercice', '/exercise/'.$this->exerciseId, '/course/details/'.$this->exerciseCourseId] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403);
        }
        foreach ([$this->exerciseCourseId, null] as $id) {
            $this->submitExercise($token, $id);
            self::assertResponseStatusCodeSame(403);
        }
        self::assertSame(0, $this->em()->getRepository(ExerciceAttempt::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(Lesson::class)->count([]));
    }

    public function testAjaxProgressExcludesHiddenValidationsFromBothSidebars(): void
    {
        $this->submitExercise($this->exerciseToken(), $this->exerciseCourseId);
        self::assertResponseIsSuccessful();
        $this->publish($this->exerciseCourseId, false);
        $page = $this->client->request('GET', self::SECTION.'/lecture');
        $token = $page->filter('#completion-button input')->attr('value');
        $this->client->request('POST', '/course/confirmation/'.$this->readingId, ['_token' => $token], server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        foreach (['desktop', 'mobile'] as $prefix) {
            self::assertStringContainsString('1/2 complété(s)', $data['progress'][$prefix]);
            self::assertStringNotContainsString('Exercice suspendu', $data['progress'][$prefix]);
        }
        self::assertSame(2, $this->em()->getRepository(Lesson::class)->count([]));
    }

    public function testSharedExerciseCanUsePublishedCourseButCannotValidateHiddenCourse(): void
    {
        $token = $this->exerciseToken();
        $course = $this->em()->find(Courses::class, $this->readingId)->setIsFree(false)->setContentType(CourseContentType::Exercise)
            ->setExercice($this->em()->find(Exercice::class, $this->exerciseId));
        $this->em()->flush();
        $this->publish($this->exerciseCourseId, false);
        $this->client->request('GET', '/exercise/'.$this->exerciseId);
        self::assertResponseIsSuccessful();
        $this->submitExercise($token, $this->exerciseCourseId);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em()->getRepository(ExerciceAttempt::class)->count([]));
        self::assertFalse($this->submitExercise($token, null)['courseCompleted']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->submitExercise($token, $course->getId())['courseCompleted']);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->em()->getRepository(Lesson::class)->count(['course' => $this->exerciseCourseId]));
        self::assertSame(1, $this->em()->getRepository(Lesson::class)->count(['course' => $this->readingId]));
    }

    public function testTrulyStandaloneExerciseStillWorks(): void
    {
        $token = $this->exerciseToken();
        $this->em()->find(Courses::class, $this->exerciseCourseId)->setExercice(null)->setIsPublished(false);
        $this->em()->flush();
        $this->client->request('GET', '/exercise/'.$this->exerciseId);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->submitExercise($token, null)['courseCompleted']);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->em()->getRepository(ExerciceAttempt::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(Lesson::class)->count([]));
    }

    public function testStaleManualAndCommentAndBookmarkRequestsCannotWrite(): void
    {
        $comment = (new Comment())->setUser($this->em()->find(User::class, $this->userId))
            ->setCourse($this->em()->find(Courses::class, $this->readingId))->setContent('Commentaire conservé');
        $this->em()->persist($comment);
        $this->em()->flush();
        $page = $this->client->request('GET', self::SECTION.'/lecture');
        $manualToken = $page->filter('#completion-button input')->attr('value');
        $commentValues = $page->filter('form[name="comment_form"]')->form()->getPhpValues();
        $bookmark = $page->filter('[data-controller="course-bookmark"]');
        $bookmarkToken = $bookmark->attr('data-course-bookmark-token-value');
        $version = $bookmark->attr('data-course-bookmark-version-value');
        $this->publish($this->readingId, false);
        $before = $this->history();
        $this->client->request('POST', '/course/confirmation/'.$this->readingId, ['_token' => $manualToken], server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/course/comment/'.$this->readingId, $commentValues);
        self::assertResponseStatusCodeSame(403);
        foreach (['edit', 'delete', 'like', 'report'] as $action) {
            $this->client->request('POST', '/comments/'.$comment->getId().'/'.$action, ['content' => 'Refusé']);
            self::assertResponseStatusCodeSame(403);
        }
        $this->client->request('POST', '/course/'.$this->readingId.'/bookmark', server: [
            'HTTP_X_CSRF_TOKEN' => $bookmarkToken, 'HTTP_X_COURSE_BOOKMARK_VERSION' => $version,
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->history());
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT last_visited_course_id FROM user WHERE id = ?', [$this->userId]));
        $this->client->request('GET', self::SECTION.'/lecture');
        self::assertResponseStatusCodeSame(403);
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', self::SECTION.'/lecture');
        self::assertResponseRedirects();
    }

    public function testQuizOpenedBeforeUnpublishingCannotReadOrMutate(): void
    {
        $page = $this->client->request('GET', self::SECTION.'/quiz-cours');
        $token = $page->filter('[data-controller="quiz"]')->attr('data-quiz-token-value');
        $this->client->request('POST', '/course/'.$this->quizId.'/quiz/start', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: '{}');
        self::assertResponseIsSuccessful();
        $attemptId = json_decode($this->client->getResponse()->getContent(), true)['attemptId'];
        $this->publish($this->quizId, false);
        $before = $this->history();
        $this->client->request('GET', '/course/'.$this->quizId.'/quiz');
        self::assertResponseStatusCodeSame(403);
        foreach (['start', 'answer', 'finish', 'restart'] as $action) {
            $this->client->jsonRequest('POST', '/course/'.$this->quizId.'/quiz/'.$action, ['attemptId' => $attemptId], ['HTTP_X_CSRF_TOKEN' => $token]);
            self::assertResponseStatusCodeSame(403);
        }
        self::assertSame($before, $this->history());
        $this->publish($this->quizId, true);
        $this->client->request('GET', '/course/'.$this->quizId.'/quiz');
        self::assertResponseIsSuccessful();
        self::assertSame($attemptId, json_decode($this->client->getResponse()->getContent(), true)['attemptId']);
    }

    public function testBookmarkFallbackAndEmptyProgramPreserveHistoricalReference(): void
    {
        $user = $this->em()->find(User::class, $this->userId);
        $user->setLastVisitedCourse($this->em()->find(Courses::class, $this->exerciseCourseId));
        $this->em()->flush();
        $this->publish($this->exerciseCourseId, false);
        $this->client->request('GET', '/ma-formation');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#resume-course-title');
        self::assertSelectorNotExists('a[href="'.self::SECTION.'/exercice"]');
        self::assertSelectorExists('a[href="'.self::SECTION.'/quiz-cours"]');
        foreach ([$this->quizId, $this->readingId] as $id) {
            $this->publish($id, false);
        }
        foreach (['/ma-formation', self::SECTION] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('#resume-course-title');
            self::assertSelectorNotExists('[role="progressbar"]');
            self::assertSelectorNotExists('[data-sidebar-complete="true"]');
            self::assertSelectorNotExists('[data-sidebar-continuable="true"]');
        }
        self::assertSame($this->exerciseCourseId, (int) $this->em()->getConnection()->fetchOne('SELECT last_visited_course_id FROM user WHERE id = ?', [$this->userId]));
        $this->publish($this->exerciseCourseId, true);
        $this->client->request('GET', '/ma-formation');
        self::assertSelectorTextContains('#resume-course-title', 'Reprendre');
        self::assertSelectorExists('a[href="'.self::SECTION.'/exercice"]');
    }

    public function testCompletionAndDictationPrerequisitesUseOnlyPublishedCourses(): void
    {
        $em = $this->em();
        $user = $em->find(User::class, $this->userId);
        $reading = $em->find(Courses::class, $this->readingId);
        $section = $reading->getSection();
        $lesson = (new Lesson())->setName('Lecture')->setUser($user)->setCourse($reading)->setStatus(LessonStatus::DONE);
        $em->persist($lesson);
        $em->flush();
        $this->publish($this->quizId, false);
        $this->publish($this->exerciseCourseId, false);
        $em->clear();
        $user = $em->find(User::class, $this->userId);
        $section = $em->find(Courses::class, $this->readingId)->getSection();
        $completion = new SectionCompletionService();
        self::assertTrue($completion->calculate([$section], [$this->readingId])[$section->getId()]);
        $progress = new DictationCourseProgress($em, $completion);
        $em->getConnection()->beginTransaction();
        try {
            self::assertSame(['complete' => true, 'started' => true], $progress->read($user, $section));
            $this->publish($this->readingId, false);
            self::assertSame(['complete' => false, 'started' => false], $progress->read($user, $section));
            self::assertFalse($completion->calculate([$section], [$this->readingId])[$section->getId()]);
            $this->publish($this->readingId, true);
            self::assertSame(['complete' => true, 'started' => true], $progress->read($user, $section));
            $this->publish($this->exerciseCourseId, true);
            self::assertSame(['complete' => false, 'started' => true], $progress->read($user, $section));
        } finally {
            $em->getConnection()->rollBack();
        }
        self::assertSame(1, $em->getRepository(Lesson::class)->count([]));
    }

    /** @return array<string, array> */
    private function history(): array
    {
        $result = [];
        foreach (['lesson', 'exercice_attempt', 'quiz_attempt', 'comments'] as $table) {
            $result[$table] = $this->em()->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }

        return $result;
    }
}
