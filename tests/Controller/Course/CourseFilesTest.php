<?php

declare(strict_types=1);

namespace App\Tests\Controller\Course;

use App\Command\VerifyCourseFilesCommand;
use App\Entity\Courses;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CourseContentType;
use App\Enum\CourseFileKind;
use App\Enum\SubscriptionStatus;
use App\Enum\UserAccountStatus;
use App\Services\Courses\CourseDurationEstimator;
use App\Services\Courses\CourseFileService;
use App\Services\Courses\CourseFileStorage;
use App\Tests\Support\QuizPlayerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class CourseFilesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Courses $course;
    private User $user;
    private string $root;
    /** @var array{?string, ?string} */
    private array $previousUrls;
    private const BYTES = 'PRIVATE_MEDIA_0123456789';

    protected function setUp(): void
    {
        $this->previousUrls = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $this->root = $_SERVER['COURSE_STORAGE_DIR'];
        self::assertStringContainsString('orthogram-course-tests-', $this->root);
        foreach (['files', 'audios', 'videos'] as $kind) {
            if (!is_dir($this->root.'/'.$kind)) {
                mkdir($this->root.'/'.$kind);
            }
        }
        file_put_contents($this->root.'/audios/fixture.mp3', self::BYTES);
        file_put_contents($this->root.'/videos/fixture.mp4', self::BYTES);
        file_put_contents($this->root.'/files/fixture.html.twig', '{{ course.name }}|{{ section.name }}|{{ program.name }}<p>'.str_repeat('mot ', 360).'</p>');
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $this->em->getConnection();
        self::assertSame('pdo_sqlite', $connection->getParams()['driver']);
        self::assertTrue($connection->getParams()['memory'] ?? false);
        self::assertSame('', $connection->fetchAssociative('PRAGMA database_list')['file']);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        [$this->user, $this->course] = QuizPlayerFactory::create($this->em);
        $this->course->setContentType(CourseContentType::Audio)->setQuiz(null)->setIsFree(true)
            ->setAudioFileName('fixture.mp3')->setVideoName('fixture.mp4')->setPartialFileName('fixture.html.twig');
        $this->em->flush();
        $this->client->loginUser($this->user);
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

    private function url(string $kind = 'audio', bool $admin = false): string
    {
        return $admin ? '/admin/course-files/'.$this->course->getId().'/'.$kind : '/courses/'.$this->course->getId().'/media/'.$kind;
    }

    private function profile(string $profile): void
    {
        $this->user = $this->em->find(User::class, $this->user->getId());
        $this->user->setRoles(str_contains($profile, 'admin') ? ['ROLE_ADMIN'] : []);
        if ('subscriber' === $profile) {
            $subscription = (new Subscription())->setUser($this->user)->setEmail($this->user->getEmail())
                ->setStatus(SubscriptionStatus::ACTIVE)->setStartsAt(new \DateTimeImmutable('-1 day'))->setEndsAt(new \DateTimeImmutable('+1 day'));
            $this->user->addSubscription($subscription);
            $this->em->persist($subscription);
        }
        if (str_starts_with($profile, 'inactive')) {
            $this->user->setAccountStatus(UserAccountStatus::INACTIVE);
        }
        $this->em->flush();
        $this->client->loginUser($this->user);
        if (str_starts_with($profile, '2fa')) {
            $token = new UsernamePasswordToken($this->user, 'main', $this->user->getRoles());
            $session = $this->client->getSession();
            $session->set('_security_main', serialize(new TwoFactorToken($token, null, 'main', ['email'])));
            $session->save();
        }
        if ('anonymous' === $profile) {
            $this->client->getCookieJar()->clear();
        }
    }

    /** @return iterable<string, array{string, string, bool, bool}> */
    public static function accessCases(): iterable
    {
        foreach (['anonymous', 'unsubscribed', 'subscriber', 'admin', 'inactive', 'inactive-admin', '2fa', '2fa-admin'] as $profile) {
            foreach (['GET', 'HEAD', 'Range', 'conditional'] as $method) {
                foreach ([false, true] as $admin) {
                    foreach ([false, true] as $free) {
                        yield $profile.'-'.$method.($admin ? '-download' : '-media').($free ? '-free' : '-paid') => [$profile, $method, $admin, $free];
                    }
                }
            }
        }
    }

    #[DataProvider('accessCases')]
    public function testAuthorizationPrecedesAllFileResponses(string $profile, string $method, bool $admin, bool $free): void
    {
        $this->course->setIsFree($free);
        $this->em->flush();
        $this->profile($profile);
        $headers = match ($method) {
            'Range' => ['HTTP_RANGE' => 'bytes=2-7'],
            'conditional' => ['HTTP_IF_MODIFIED_SINCE' => 'Wed, 01 Jan 2031 00:00:00 GMT'],
            default => [],
        };
        $this->client->request('HEAD' === $method ? 'HEAD' : 'GET', $this->url($admin ? 'source' : 'audio', $admin), server: $headers);
        $response = $this->client->getResponse();
        $allowed = 'admin' === $profile || (!$admin && ('subscriber' === $profile || ($free && in_array($profile, ['anonymous', 'unsubscribed'], true))));
        if (!$allowed) {
            self::assertContains($response->getStatusCode(), [302, 403]);
            self::assertNotInstanceOf(BinaryFileResponse::class, $response);
            self::assertStringNotContainsString(self::BYTES, (string) $response->getContent());
            self::assertFalse($response->headers->has('Content-Range'));
            self::assertFalse($response->headers->has('Last-Modified'));

            return;
        }
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertResponseStatusCodeSame(match ($method) {
            'Range' => 206, 'conditional' => 304, default => 200,
        });
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->has('X-Sendfile'));
        self::assertFalse($response->headers->has('X-Accel-Redirect'));
        if (in_array($method, ['HEAD', 'conditional'], true)) {
            self::assertSame('', $this->client->getInternalResponse()->getContent());
        } elseif (!$admin) {
            self::assertSame('Range' === $method ? substr(self::BYTES, 2, 6) : self::BYTES, $this->client->getInternalResponse()->getContent());
        }
        if ($admin && 'conditional' !== $method) {
            self::assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
            self::assertSame('application/octet-stream', $response->headers->get('Content-Type'));
            if ('GET' === $method) {
                self::assertStringContainsString('{{ course.name }}', $this->client->getInternalResponse()->getContent());
            }
        }
        self::assertStringNotContainsString($this->root, (string) $response->headers);
    }

    public function testRevokedSubscriptionCannotReuseRangeOrValidator(): void
    {
        $this->course->setIsFree(false);
        $this->em->flush();
        $this->profile('subscriber');
        $this->client->request('GET', $this->url());
        self::assertResponseIsSuccessful();
        $lastModified = $this->client->getResponse()->headers->get('Last-Modified');
        $user = $this->em->find(User::class, $this->user->getId());
        foreach ($user->getSubscriptions() as $subscription) {
            $subscription->setStatus(SubscriptionStatus::CANCELLED);
        }
        $this->em->flush();
        // Same session, no loginUser() after revocation.
        foreach ([['HTTP_RANGE' => 'bytes=0-3'], ['HTTP_IF_MODIFIED_SINCE' => $lastModified], ['HTTP_IF_NONE_MATCH' => '*']] as $headers) {
            $this->client->request('GET', $this->url(), server: $headers);
            self::assertResponseStatusCodeSame(403);
            self::assertNotInstanceOf(BinaryFileResponse::class, $this->client->getResponse());
        }
    }

    /** @return iterable<string, array{string, CourseContentType}> */
    public static function freeMediaProfiles(): iterable
    {
        foreach (['anonymous', 'unsubscribed'] as $profile) {
            foreach ([CourseContentType::Audio, CourseContentType::Video] as $type) {
                yield $profile.'-'.$type->value => [$profile, $type];
            }
        }
    }

    #[DataProvider('freeMediaProfiles')]
    public function testFreeMediaWithdrawalPrecedesHeadRangeAndValidators(string $profile, CourseContentType $type): void
    {
        $this->course->setContentType($type);
        $this->em->flush();
        $this->profile($profile);
        $this->client->request('GET', $this->url($type->value));
        self::assertResponseIsSuccessful();
        self::assertSame(self::BYTES, $this->client->getInternalResponse()->getContent());
        $lastModified = $this->client->getResponse()->headers->get('Last-Modified');
        $this->em->find(Courses::class, $this->course->getId())->setIsFree(false);
        $this->em->flush();
        foreach (['GET' => [], 'HEAD' => [], 'Range' => ['HTTP_RANGE' => 'bytes=0-3'],
            'modified' => ['HTTP_IF_MODIFIED_SINCE' => $lastModified], 'etag' => ['HTTP_IF_NONE_MATCH' => '*']] as $method => $headers) {
            $this->client->request('HEAD' === $method ? 'HEAD' : 'GET', $this->url($type->value), server: $headers);
            self::assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);
            self::assertNotInstanceOf(BinaryFileResponse::class, $this->client->getResponse());
            self::assertFalse($this->client->getResponse()->headers->has('Content-Range'));
            self::assertFalse($this->client->getResponse()->headers->has('Last-Modified'));
            self::assertStringNotContainsString(self::BYTES, $this->client->getInternalResponse()->getContent());
        }
    }

    public function testMissingWrongKindAndRawSourcesAreNotServed(): void
    {
        foreach (['video', 'source', '../files/fixture.html.twig', 'invalid'] as $kind) {
            $this->client->request('GET', $this->url($kind));
            self::assertResponseStatusCodeSame(404);
        }
        $course = $this->em->find(Courses::class, $this->course->getId());
        foreach (['absent.mp3', '../files/fixture.html.twig', 'C:\\secret.mp3'] as $name) {
            $course->setAudioFileName($name);
            $this->em->flush();
            $this->client->request('GET', $this->url());
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testVideoRangesAndRenderedPlayersUseControlledUrls(): void
    {
        $this->profile('subscriber');
        foreach ([CourseContentType::Audio, CourseContentType::Video] as $type) {
            $this->course = $this->em->find(Courses::class, $this->course->getId());
            $this->course->setContentType($type);
            $this->em->flush();
            $this->client->request('GET', '/courses/quiz-formation/quiz-section/quiz-cours');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists($type->value.'[src="'.$this->url($type->value).'"]');
            foreach (['files', 'audios', 'videos'] as $directory) {
                self::assertStringNotContainsString('/courses/'.$directory.'/', $this->client->getResponse()->getContent());
            }
            $this->client->request('GET', $this->url($type->value), server: ['HTTP_RANGE' => 'bytes=4-8', 'HTTP_X_SENDFILE_TYPE' => 'X-Sendfile']);
            self::assertResponseStatusCodeSame(206);
            self::assertSame(substr(self::BYTES, 4, 5), $this->client->getInternalResponse()->getContent());
            $lastModified = $this->client->getResponse()->headers->get('Last-Modified');
            $this->client->request('GET', $this->url($type->value), server: ['HTTP_RANGE' => 'bytes=4-8', 'HTTP_IF_RANGE' => $lastModified]);
            self::assertResponseStatusCodeSame(206);
            $this->client->request('GET', $this->url($type->value), server: ['HTTP_RANGE' => 'bytes=4-8', 'HTTP_IF_RANGE' => 'Wed, 01 Jan 2020 00:00:00 GMT']);
            self::assertResponseStatusCodeSame(200);
            self::assertSame(self::BYTES, $this->client->getInternalResponse()->getContent());
        }
        $this->client->request('GET', $this->url('video'), server: ['HTTP_RANGE' => 'bytes=9999-10000']);
        self::assertResponseStatusCodeSame(416);
    }

    public function testTwigContextDurationAndMissingBehavior(): void
    {
        $this->course->setContentType(CourseContentType::Twig);
        $this->em->flush();
        $service = self::getContainer()->get(CourseFileService::class);
        $html = $service->getFileContent($this->course);
        self::assertStringContainsString('Quiz cours|Quiz section|Quiz formation', $html);
        self::assertSame(3, (new CourseDurationEstimator())->estimateReadingDuration($html));
        $this->client->request('GET', '/courses/quiz-formation/quiz-section/quiz-cours');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Quiz cours|Quiz section|Quiz formation', $this->client->getResponse()->getContent());
        $this->course->setPartialFileName('missing.html.twig');
        $this->em->flush();
        self::assertNull($service->getFileContent($this->course));
        $this->client->request('GET', '/courses/quiz-formation/quiz-section/quiz-cours');
        self::assertSelectorTextContains('.v9d3ks', 'Le contenu de ce cours sera bientôt disponible.');
    }

    public function testPrivateResolutionRejectsMalformedNames(): void
    {
        $storage = self::getContainer()->get(CourseFileStorage::class);
        self::assertSame(realpath($this->root.'/files/fixture.html.twig'), $storage->resolve($this->course, CourseFileKind::Source));
        foreach ([null, '', '.', '..', '../fixture.html.twig', '..\\fixture.html.twig', '/tmp/x', 'C:\\x', 'php://filter', "bad\0name", 'fixture.html.twig:stream'] as $name) {
            $this->course->setPartialFileName($name);
            self::assertNull($storage->resolve($this->course, CourseFileKind::Source));
        }
    }

    public function testReferenceVerificationAndEntityRemoval(): void
    {
        $command = new CommandTester(self::getContainer()->get(VerifyCourseFilesCommand::class));
        self::assertSame(0, $command->execute([]));
        self::assertStringContainsString('3 references checked; 0 missing', $command->getDisplay());
        $this->course->setVideoName('absent.mp4');
        $this->em->flush();
        self::assertSame(1, $command->execute([]));
        self::assertStringContainsString('kind=video', $command->getDisplay());
        self::assertStringNotContainsString($this->root, $command->getDisplay());
        $this->course->setVideoName('fixture.mp4');
        $this->em->flush();
        $this->em->remove($this->course);
        $this->em->flush();
        foreach (['files/fixture.html.twig', 'audios/fixture.mp3', 'videos/fixture.mp4'] as $file) {
            self::assertFileDoesNotExist($this->root.'/'.$file);
        }
    }

    public function testEscapingSymlinkIsRefused(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'orthogram-outside-');
        $link = $this->root.'/files/escape.html.twig';
        try {
            if (!@symlink($outside, $link)) {
                self::markTestSkipped('OS does not permit file symlinks for this user.');
            }
            $this->course->setPartialFileName('escape.html.twig');
            self::assertNull(self::getContainer()->get(CourseFileStorage::class)->resolve($this->course, CourseFileKind::Source));
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            unlink($outside);
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function uploads(): iterable
    {
        yield 'twig' => ['partialFile', 'partialFileName', 'source'];
        yield 'audio' => ['audioFile', 'audioFileName', 'audio'];
        yield 'video' => ['videoFile', 'videoName', 'video'];
    }

    #[DataProvider('uploads')]
    public function testAdminUploadReplaceDownloadAndDelete(string $field, string $property, string $kind): void
    {
        $enum = CourseFileKind::from($kind);
        $setter = 'set'.ucfirst($property);
        $getter = 'get'.ucfirst($property);
        $this->course->$setter(null);
        $this->course->setContentType('source' === $kind ? CourseContentType::Twig : CourseContentType::from($kind));
        $this->em->flush();
        $old = null;
        foreach ([1, 2] as $version) {
            $source = tempnam(sys_get_temp_dir(), 'orthogram-upload-');
            $content = 'video' === $kind ? hex2bin('000000186674797069736F6D0000000069736F6D69736F32').'fixture'.$version : '<p>'.str_repeat('mot ', 181 * $version).'</p>';
            file_put_contents($source, $content);
            try {
                $this->submit([], [$field => ['file' => new UploadedFile($source, 'fixture.'.match ($enum) {
                    CourseFileKind::Source => 'html.twig', CourseFileKind::Audio => 'mp3', CourseFileKind::Video => 'mp4',
                }, null, null, true)]]);
                self::assertResponseRedirects();
                $course = $this->em->find(Courses::class, $this->course->getId());
                $name = $course->$getter();
                $path = $this->root.'/'.$enum->directory().'/'.$name;
                self::assertSame($content, file_get_contents($path));
                if (null !== $old) {
                    self::assertNotSame($old, $path);
                    self::assertFileDoesNotExist($old);
                }
                $old = $path;
                $this->client->request('GET', '/admin/courses/'.$course->getId().'/edit');
                self::assertSelectorTextContains('a[href="'.$this->url($kind, true).'"]', $name);
                $this->client->request('GET', $this->url($kind, true));
                self::assertResponseIsSuccessful();
                self::assertSame($content, $this->client->getInternalResponse()->getContent());
                self::assertSame('application/octet-stream', $this->client->getResponse()->headers->get('Content-Type'));
                if ('source' === $kind) {
                    self::assertSame(1 === $version ? 2 : 3, $course->getDurationMinutes());
                }
            } finally {
                if (is_file($source)) {
                    unlink($source);
                }
            }
        }
        $this->submit([$field => ['delete' => '1']]);
        self::assertResponseRedirects();
        self::assertFileDoesNotExist($old);
        self::assertNull($this->em->find(Courses::class, $this->course->getId())->$getter());
        $this->client->request('GET', $this->url($kind, true));
        self::assertResponseStatusCodeSame(404);
    }

    /** @param array<string, mixed> $payload
     * @param array<string, mixed> $files
     */
    private function submit(array $payload = [], array $files = []): void
    {
        $page = $this->client->request('GET', '/admin/courses/'.$this->course->getId().'/edit');
        self::assertResponseIsSuccessful();
        $form = $page->filter('form[name="Courses"]')->form();
        $values = $form->getPhpValues();
        $values['Courses'] = array_replace($values['Courses'], $payload);
        $values['ea']['newForm']['btn'] = 'saveAndReturn';
        $this->client->request('POST', $form->getUri(), $values, ['Courses' => $files]);
    }
}
