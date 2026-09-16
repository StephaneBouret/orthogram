<?php

namespace App\Tests\Controller\Course;

use App\Entity\Courses;
use App\Entity\Lesson;
use App\Entity\Sections;
use App\Entity\User;
use App\Enum\CourseContentType;
use App\Enum\LessonStatus;
use App\Tests\Support\QuizPlayerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SidebarCompletionTest extends WebTestCase
{
    private const COURSE_URL = '/courses/quiz-formation/quiz-section/quiz-cours';

    private KernelBrowser $client;
    private string $database;
    private int $courseId;
    private int $sectionId;
    private int $emptySectionId;
    private int $otherUserId;
    private int $userId;
    private ?string $previousEnvUrl;
    private ?string $previousServerUrl;

    protected function setUp(): void
    {
        $this->previousEnvUrl = $_ENV['DATABASE_URL'] ?? null;
        $this->previousServerUrl = $_SERVER['DATABASE_URL'] ?? null;
        $this->database = str_replace('\\', '/', dirname(__DIR__, 3).'/var/sidebar-'.bin2hex(random_bytes(8)).'.sqlite');
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///'.$this->database;
        $this->client = self::createClient();
        $this->client->catchExceptions(false);
        // Verify isolation before any schema or fixture writes.
        self::assertSame('pdo_sqlite', $this->em()->getConnection()->getParams()['driver']);
        self::assertSame($this->database, $this->em()->getConnection()->getParams()['path']);
        (new SchemaTool($this->em()))->createSchema($this->em()->getMetadataFactory()->getAllMetadata());
        [$user, $course] = QuizPlayerFactory::create($this->em());
        $course->setContentType(CourseContentType::Twig)->setPosition(0);
        $section = $course->getSection();
        $lastCourse = (new Courses())->setName('Cours suivant')->setSlug('cours-suivant')->setSection($section)->setPosition(1);
        $emptySection = (new Sections())->setName('Section vide')->setSlug('section-vide')->setProgram($section->getProgram());
        $otherUser = QuizPlayerFactory::user('sidebar-other@example.test');
        $lesson = (new Lesson())->setName('Cours suivant')->setUser($user)->setCourse($lastCourse)->setStatus(LessonStatus::DONE);
        foreach ([$lastCourse, $emptySection, $otherUser, $lesson] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->courseId = $course->getId();
        $this->sectionId = $section->getId();
        $this->emptySectionId = $emptySection->getId();
        $this->otherUserId = $otherUser->getId();
        $this->userId = $user->getId();
        $this->client->loginUser($user);
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

    public function testRenderOnAllPagesAndOutOfOrderValidationThenCancellation(): void
    {
        $this->assertAllPages(false);
        // Position 1 is already done: validating position 0 completes the section.
        $this->toggleCourse();
        $this->assertAllPages(true);
        $this->toggleCourse();
        $this->assertAllPages(false);
    }

    public function testCompletionBelongsToUserAndNewCourseReopensSection(): void
    {
        $this->toggleCourse();
        $this->client->loginUser($this->em()->find(User::class, $this->otherUserId));
        $this->assertAllPages(false);
        // The second user completes only the first course, not the others.
        $this->toggleCourse(false);
        $this->assertAllPages(false);

        $this->client->loginUser($this->em()->find(User::class, $this->userId));
        $this->assertAllPages(true);
        $course = $this->em()->find(Courses::class, $this->courseId);
        $newCourse = (new Courses())->setName('Nouveau cours')->setSlug('nouveau-cours')->setSection($course->getSection())->setPosition(2);
        $this->em()->persist($newCourse);
        $this->em()->flush();
        $this->assertAllPages(false);
    }

    public function testFailedValidationDoesNotCompleteSection(): void
    {
        $this->client->catchExceptions(true);
        $this->client->request('POST', '/course/confirmation/'.$this->courseId, ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $this->assertAllPages(false);
        self::assertSame(0, $this->em()->getRepository(Lesson::class)->count(['course' => $this->courseId]));
    }

    private function toggleCourse(?bool $expectedComplete = null): void
    {
        $crawler = $this->client->request('GET', self::COURSE_URL);
        $wasDone = str_contains($crawler->filter('#completion-button')->text(), 'Terminé');
        $this->client->submit($crawler->filter('#completion-button form')->form());
        self::assertResponseRedirects(self::COURSE_URL);
        $this->client->followRedirect();
        $this->assertSidebar($expectedComplete ?? !$wasDone);
    }

    private function assertAllPages(bool $complete): void
    {
        foreach (['/courses/quiz-formation', '/courses/quiz-formation/quiz-section', self::COURSE_URL] as $index => $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('head meta[name="turbo-cache-control"][content="no-cache"]');
            $this->assertSidebar($complete);
            self::assertSelectorCount($index > 0 ? 2 : 0, '.course-section-link.is-active');
        }
    }

    private function assertSidebar(bool $complete): void
    {
        foreach (['desktop', 'mobile'] as $prefix) {
            $id = $prefix.'-collapse-'.$this->sectionId;
            self::assertSelectorExists('#'.$id.'.collapse'.($complete ? ':not(.show)' : '.show'));
            $button = 'button[aria-controls="'.$id.'"]';
            self::assertSelectorExists($button.'[type="button"][data-bs-target="#'.$id.'"][aria-expanded="'.($complete ? 'false' : 'true').'"]');
            self::assertSelectorExists($button.($complete ? '.collapsed' : ':not(.collapsed)'));
            self::assertSelectorExists($button.' .closed'.($complete ? ':not(.d-none)' : '.d-none'));
            self::assertSelectorExists($button.' .opened'.($complete ? '.d-none' : ':not(.d-none)'));
            self::assertSelectorExists('#'.$prefix.'-collapse-'.$this->emptySectionId.'.collapse.show');
        }
    }
}
