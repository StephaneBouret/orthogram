<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Courses;
use App\Entity\Exercice;
use App\Entity\Lesson;
use App\Entity\Quiz;
use App\Enum\CourseContentType;
use App\Tests\Support\QuizPlayerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class CoursesFreeAccessTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Courses $course;
    private Exercice $exercise;
    private Quiz $quiz;
    private ?string $previousEnvUrl;
    private ?string $previousServerUrl;

    protected function setUp(): void
    {
        $this->previousEnvUrl = $_ENV['DATABASE_URL'] ?? null;
        $this->previousServerUrl = $_SERVER['DATABASE_URL'] ?? null;
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->catchExceptions(false);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $this->em->getConnection();
        // Check configuration and actual target BEFORE any schema or fixture writes.
        self::assertSame('pdo_sqlite', $connection->getParams()['driver']);
        self::assertTrue($connection->getParams()['memory'] ?? false);
        self::assertSame('', $connection->fetchAssociative('PRAGMA database_list')['file']);
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        [$admin, $this->course] = QuizPlayerFactory::create($this->em);
        $this->quiz = $this->course->getQuiz();
        $this->course->setDurationMinutes(7);
        $this->exercise = (new Exercice())->setTitle('Exercice test')->setInstruction('Repérez les noms.');
        $sibling = (new Courses())->setName('Cours voisin')->setSlug('cours-voisin')->setSection($this->course->getSection())->setPosition(1)->setDurationMinutes(5);
        $lesson = (new Lesson())->setName('Progression existante')->setUser($admin)->setCourse($this->course);
        foreach ([$this->exercise, $sibling, $lesson] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->client->loginUser($admin);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->getConnection()->close();
        }
        parent::tearDown();
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

    public function testDefaultFormAndReadOnlyIndex(): void
    {
        $this->client->request('GET', '/admin/courses/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="Courses[isFree]"]:not([checked])');
        self::assertSelectorTextContains('form[name="Courses"]', 'Consultation uniquement, sans progression, commentaires, quiz ni exercices interactifs.');
        $this->client->request('GET', '/admin/courses');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('td[data-column="isFree"]');
        self::assertSelectorNotExists('td[data-column="isFree"] input');
        self::assertSelectorNotExists('[data-toggle-url]');
    }

    /** @return iterable<string, array{string}> */
    public static function allowedTypes(): iterable
    {
        foreach (['twig', 'audio', 'video', 'link'] as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('allowedTypes')]
    public function testCreateEnableAndDisableFreeAccess(string $type): void
    {
        $this->submit('/admin/courses/new', ['name' => 'Cours créé', 'contentType' => $type, 'section' => (string) $this->course->getSection()->getId(), 'durationMinutes' => '9']);
        self::assertResponseRedirects();
        $created = $this->em->getRepository(Courses::class)->findOneBy(['name' => 'Cours créé']);
        self::assertFalse($created->isFree());
        $url = '/admin/courses/'.$created->getId().'/edit';
        $before = $this->rows();
        $this->submit($url, ['isFree' => '1']);
        self::assertResponseRedirects();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT is_free FROM courses WHERE id = ?', [$created->getId()]));
        $this->submit($url, ['isFree' => null]);
        self::assertResponseRedirects();
        self::assertSame($before, $this->rows(), 'Only the free flag may change during this round trip.');
    }

    /** @return iterable<string, array{string}> */
    public static function interactiveTypes(): iterable
    {
        yield 'quiz' => ['quiz'];
        yield 'exercise' => ['exercise'];
    }

    #[DataProvider('interactiveTypes')]
    public function testForgedFreeCreationIsRejectedWithoutWrites(string $type): void
    {
        $before = $this->rows();
        $this->submit('/admin/courses/new', [
            'name' => 'Création interdite', 'contentType' => $type, 'isFree' => '1',
            'section' => (string) $this->course->getSection()->getId(),
            'quiz' => (string) $this->quiz->getId(), 'exercice' => (string) $this->exercise->getId(),
        ]);
        $this->assertFreeError();
        self::assertSame($before, $this->rows());
    }

    #[DataProvider('interactiveTypes')]
    public function testInvalidEditPreservesAllRowsAndAssociations(string $type): void
    {
        if ('exercise' === $type) {
            $this->course->setContentType(CourseContentType::Exercise)->setQuiz(null)->setExercice($this->exercise);
            $this->em->flush();
        }
        $before = $this->rows();
        // Changing to the OTHER interactive type would detach the original association on success.
        $this->submit($this->editUrl(), [
            'name' => 'Modification interdite', 'position' => '1', 'shortDescription' => 'Ne pas enregistrer',
            'contentType' => 'quiz' === $type ? 'exercise' : 'quiz', 'isFree' => '1',
            'quiz' => (string) $this->quiz->getId(), 'exercice' => (string) $this->exercise->getId(),
        ]);
        $this->assertFreeError();
        self::assertSame($before, $this->rows());
    }

    #[DataProvider('interactiveTypes')]
    public function testFreeCourseTypeChangeRequiresExplicitUncheck(string $type): void
    {
        $this->course->setContentType(CourseContentType::Twig)->setQuiz(null)->setIsFree(true);
        $this->em->flush();
        $before = $this->rows();
        $payload = ['contentType' => $type, 'quiz' => (string) $this->quiz->getId(), 'exercice' => (string) $this->exercise->getId()];
        $this->submit($this->editUrl(), $payload + ['isFree' => '1']);
        $this->assertFreeError();
        self::assertSame($before, $this->rows());

        // Discard the invalid bound entity as a new HTTP request would do.
        $id = $this->course->getId();
        $this->em->clear();
        $this->course = $this->em->find(Courses::class, $id);
        $this->submit($this->editUrl(), $payload + ['isFree' => null]);
        self::assertResponseRedirects();
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM courses WHERE id = ?', [$id]);
        self::assertSame(0, (int) $row['is_free']);
        self::assertSame($type, $row['content_type']);
        self::assertSame('quiz' === $type ? $payload['quiz'] : null, null === $row['quiz_id'] ? null : (string) $row['quiz_id']);
        self::assertSame('exercise' === $type ? $payload['exercice'] : null, null === $row['exercice_id'] ? null : (string) $row['exercice_id']);
        foreach (['quiz', 'quiz_question', 'quiz_answer', 'exercice', 'lesson'] as $table) {
            self::assertSame($before[$table], $this->rows()[$table]);
        }
    }

    public function testForgedAjaxToggleIsRefusedEvenWithValidCsrf(): void
    {
        $this->client->request('GET', $this->editUrl());
        $session = self::getContainer()->get('session.factory')->createSession();
        $session->setId($this->client->getCookieJar()->get($session->getName())->getValue());
        $session->set('_csrf/'.BooleanField::CSRF_TOKEN_NAME, 'forged-toggle-test');
        $session->save();
        $before = $this->rows();
        $this->client->catchExceptions(true);
        $this->client->request('PATCH', $this->editUrl().'?'.http_build_query([
            'fieldName' => 'isFree', 'newValue' => 'true', 'csrfToken' => 'forged-toggle-test',
        ]), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame($before, $this->rows());
    }

    public function testFreeCreationKeepsUploadDurationAndCourseOrdering(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'orthogram_free_');
        $directory = $_SERVER['COURSE_STORAGE_DIR'].'/files/';
        $beforeFiles = glob($directory.'lot1-gratuite-test-*.html.twig');
        $content = '<p>'.str_repeat('orthographe ', 181).'</p>';
        file_put_contents($source, $content);
        try {
            $this->submit('/admin/courses/new', [
                'name' => 'Cours téléversé gratuit', 'contentType' => 'twig', 'isFree' => '1',
                'section' => (string) $this->course->getSection()->getId(), 'durationMinutes' => '',
            ], ['Courses' => ['partialFile' => ['file' => new UploadedFile($source, 'lot1-gratuite-test.html.twig', 'text/plain', null, true)]]]);
            self::assertResponseRedirects();
            $course = $this->em->getRepository(Courses::class)->findOneBy(['name' => 'Cours téléversé gratuit']);
            self::assertTrue($course->isFree());
            self::assertSame(2, $course->getDurationMinutes());
            self::assertSame(2, $course->getPosition());
            $filename = $course->getPartialFileName();
            self::assertSame($content, file_get_contents($directory.$filename));

            $this->submit('/admin/courses/'.$course->getId().'/edit', ['isFree' => null, 'position' => '0']);
            self::assertResponseRedirects();
            $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM courses WHERE id = ?', [$course->getId()]);
            self::assertSame(0, (int) $row['is_free']);
            self::assertSame(2, (int) $row['duration_minutes']);
            self::assertSame($filename, $row['partial_file_name']);
            self::assertSame($content, file_get_contents($directory.$filename));
            self::assertSame(['Cours téléversé gratuit', 'Quiz cours', 'Cours voisin'], $this->em->getConnection()->fetchFirstColumn('SELECT name FROM courses ORDER BY position, id'));
        } finally {
            if (is_file($source)) {
                unlink($source);
            }
            // Remove only new files bearing this test's upload prefix; preserve all preexisting files.
            foreach (array_diff(glob($directory.'lot1-gratuite-test-*.html.twig'), $beforeFiles) as $path) {
                unlink($path);
            }
        }
    }

    public function testFreeFlagDoesNotOpenTheReader(): void
    {
        $this->course->setContentType(CourseContentType::Twig)->setQuiz(null)->setIsFree(true);
        $user = QuizPlayerFactory::user('without-subscription@example.test')->setRoles([]);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->catchExceptions(true);
        $this->client->loginUser($user);
        $this->client->request('GET', '/courses/quiz-formation/quiz-section/quiz-cours');
        self::assertResponseStatusCodeSame(403);
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/courses/quiz-formation/quiz-section/quiz-cours');
        self::assertResponseRedirects('/login');
    }

    private function editUrl(): string
    {
        return '/admin/courses/'.$this->course->getId().'/edit';
    }

    private function assertFreeError(): void
    {
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#Courses_isFree.is-invalid');
        self::assertSelectorExists('#Courses_isFree[checked]');
        self::assertSelectorTextContains('.invalid-feedback', 'Les quiz et les exercices interactifs ne peuvent pas être gratuits.');
    }

    /**
     * @param array<string, string|null> $payload
     * @param array<string, mixed>       $files
     */
    private function submit(string $url, array $payload, array $files = []): void
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="Courses"]')->form();
        $values = $form->getPhpValues();
        $values['ea']['newForm']['btn'] = 'saveAndReturn';
        $values['Courses'] = array_replace($values['Courses'], $payload);
        if (array_key_exists('isFree', $payload) && null === $payload['isFree']) {
            unset($values['Courses']['isFree']);
        }
        $this->client->request('POST', $form->getUri(), $values, $files);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function rows(): array
    {
        $rows = [];
        foreach (['courses', 'quiz', 'quiz_question', 'quiz_answer', 'exercice', 'lesson', 'quiz_attempt', 'exercice_attempt', 'comments'] as $table) {
            $rows[$table] = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }

        return $rows;
    }
}
