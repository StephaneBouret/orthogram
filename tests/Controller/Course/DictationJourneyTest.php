<?php

declare(strict_types=1);

namespace App\Tests\Controller\Course;

use App\Entity\Courses;
use App\Entity\DictationAttempt;
use App\Entity\Lesson;
use App\Entity\Sections;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\DictationAction;
use App\Enum\LessonStatus;
use App\Enum\SubscriptionStatus;
use App\Services\Dictation\DictationJourney;
use App\Tests\Support\DictationFactory;
use App\Tests\Support\DictationTestDatabase;
use App\Tests\Support\QuizPlayerFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DictationJourneyTest extends WebTestCase
{
    private static DictationTestDatabase $database;
    private KernelBrowser $client;
    private User $user;
    private Courses $course;
    private Courses $free;
    private Courses $foundation;
    private int $dictationId;
    private int $versionId;
    /** @var array<string, array{mixed, mixed}> */
    private array $previous = [];

    public static function setUpBeforeClass(): void
    {
        self::$database = new DictationTestDatabase();
        try {
            self::$database->install();
        } catch (\Throwable $error) {
            self::$database->close();
            throw $error;
        }
    }

    protected function setUp(): void
    {
        foreach (['DATABASE_URL' => self::$database->kernelUrl(), 'DICTATION_LEARNER_ENABLED' => '1'] as $key => $value) {
            $this->previous[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $this->client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(self::$database->name, $em->getConnection()->fetchOne('SELECT DATABASE()'));
        [, $dictation, $version, $this->course] = DictationFactory::create($em);
        $this->dictationId = $dictation->getId();
        $this->versionId = $version->getId();
        $this->user = QuizPlayerFactory::user('journey-'.bin2hex(random_bytes(6)).'@example.test')->setRoles(['ROLE_USER']);
        $subscription = (new Subscription())->setEmail($this->user->getEmail())->setStatus(SubscriptionStatus::ACTIVE)->setIsLifetime(true);
        $this->user->addSubscription($subscription);
        $this->course->setPartialFileName('lot6-private.html.twig')->setShortDescription('SECRET_COURSE_EXCERPT');
        $this->free = (new Courses())->setName('Lecture gratuite')->setSlug('gratuit')->setSection($this->course->getSection())
            ->setIsFree(true)->setPosition(2)->setPartialFileName('lot6-free.html.twig');
        $section = (new Sections())->setName('Fondamentaux')->setSlug('fondamentaux')->setProgram($this->course->getSection()->getProgram());
        $this->foundation = (new Courses())->setName('Fondamental')->setSlug('fondamental')->setSection($section)->setPartialFileName('lot6-free.html.twig');
        foreach ([$this->user, $subscription, $this->free, $section, $this->foundation] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        self::assertContains($this->dictationId, $this->user->getDictationPrerequisites());
        $root = $_SERVER['COURSE_STORAGE_DIR'];
        self::assertStringContainsString('orthogram-course-tests-', $root);
        if (!is_dir($root.'/files')) {
            mkdir($root.'/files');
        }
        file_put_contents($root.'/files/lot6-private.html.twig', '<p>SECRET_PRIVATE_CONTENT</p>');
        file_put_contents($root.'/files/lot6-free.html.twig', '<p>CONTENU LIBRE</p>');
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->previous as $key => [$env, $server]) {
            if (null === $env) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
            if (null === $server) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$database->close();
    }

    private function url(?Courses $course = null): string
    {
        $course ??= $this->course;

        return '/courses/'.$course->getSection()->getProgram()->getSlug().'/'.$course->getSection()->getSlug().'/'.$course->getSlug();
    }

    private function sectionUrl(): string
    {
        return dirname($this->url());
    }

    /** @return array<string, mixed> */
    private function phase(?DictationAction $action = null, int $revision = 0, ?string $copy = null): array
    {
        $em = self::$database->manager();
        $user = $em->find(User::class, $this->user->getId());
        $service = DictationFactory::service($em);
        $state = $service->start($user, $this->dictationId, true);

        return null === $action ? $state : $service->transition($user, $state['attemptId'], $action, $revision, $copy, true);
    }

    public function testInitialClosureControlsDirectAccessAndLabelsWithoutGetMutation(): void
    {
        $this->client->request('GET', $this->url());
        self::assertResponseRedirects('/dictees/'.$this->dictationId);
        $crawler = $this->client->request('GET', $this->sectionUrl());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Commencer la dictée', $crawler->text());
        self::assertGreaterThanOrEqual(3, $crawler->filter('[data-dictation-position="before"]')->count());
        self::assertSame(0, self::$database->manager()->getRepository(DictationAttempt::class)->count(['user' => $this->user]));
        self::assertStringNotContainsString('SECRET_PRIVATE_CONTENT', $crawler->html());
        self::assertStringNotContainsString('SECRET_CORRECTION_TEST', $crawler->html());
        $this->phase();
        $crawler = $this->client->request('GET', $this->sectionUrl());
        self::assertStringContainsString('Poursuivre l’écriture', $crawler->text());
        $state = $this->phase(DictationAction::Evaluate, 0, 'Erreur.');
        self::assertSame('self_correction', $state['state']);
        $crawler = $this->client->request('GET', $this->sectionUrl());
        self::assertStringContainsString('Poursuivre l’autocorrection', $crawler->text());
        $this->client->request('GET', $this->url());
        self::assertResponseRedirects('/dictees/tentatives/'.$state['attemptId']);
        $this->phase(DictationAction::Close, 1, 'Erreur.');
        $this->client->request('GET', $this->url());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('SECRET_PRIVATE_CONTENT', $this->client->getResponse()->getContent());
    }

    public function testPerfectInitialCopyOpensCoursesAndNewPublicationPreservesAttempt(): void
    {
        $state = $this->phase(DictationAction::Evaluate, 0, 'L’école ouvre.');
        self::assertSame('awaiting_courses', $state['state']);
        $em = self::$database->manager();
        $editorial = new \App\Services\Dictation\DictationEditorialService($em, new \App\Services\Dictation\DictationCorrector(), \App\Tests\Support\DictationAudioFixture::storage());
        $version = $em->find(\App\Entity\DictationVersion::class, $this->versionId);
        $new = $editorial->cloneVersion($version, $version->getRevision());
        $new->edit('Nouvelle publication', 'Une autre référence.', null);
        $editorial->saveDraft($new, $new->getRevision());
        $editorial->publish($new, $new->getRevision());
        $this->client->request('GET', $this->url());
        self::assertResponseIsSuccessful();
        self::assertSame($this->versionId, $em->find(DictationAttempt::class, $state['attemptId'])->getVersion()->getId());
        self::assertSame('awaiting_courses', $this->phase()['state']);
    }

    public function testFreeReadingDoesNotExemptProtectedCoursesAndFundamentalsRemainOpen(): void
    {
        $this->client->request('GET', '/course/details/'.$this->free->getId());
        self::assertResponseIsSuccessful();
        self::assertSame($this->url($this->free), json_decode($this->client->getResponse()->getContent(), true)['url']);
        $crawler = $this->client->request('GET', $this->url($this->free));
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a.btn[href="/dictees/'.$this->dictationId.'"]')->count());
        $this->client->request('GET', $this->url($this->foundation));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $this->url());
        self::assertResponseRedirects('/dictees/'.$this->dictationId);
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $this->url($this->free));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('dictation-journey-entry', $this->client->getResponse()->getContent());
        $this->client->request('GET', $this->url());
        self::assertFalse($this->client->getResponse()->isSuccessful());
    }

    public function testFreeCourseExplainsOnlyPedagogicalBlockAndLinksToCurrentAttempt(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->free));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('CONTENU LIBRE', $crawler->text());
        self::assertSelectorTextContains('.course-prerequisite-notice', 'Vous pouvez consulter ce cours librement. Pour le valider et participer aux échanges, terminez d’abord la dictée initiale de cette section.');
        self::assertSelectorTextContains('.course-prerequisite-notice a', 'Accéder à ma dictée');
        self::assertSelectorExists('.course-prerequisite-notice a[href="/dictees/'.$this->dictationId.'"]');
        self::assertStringNotContainsString('Découvrir l’offre', $crawler->text());
        self::assertSame(0, (int) self::$database->manager()->getConnection()->fetchOne('SELECT COUNT(*) FROM dictation_attempt WHERE user_id = ?', [$this->user->getId()]));

        $this->client->click($crawler->selectLink('Accéder à ma dictée')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/dictees/'.$this->dictationId.'/commencer"]');
        $state = $this->phase();
        foreach ([null, DictationAction::Evaluate] as $action) {
            if (null !== $action) {
                $this->phase($action, 0, 'Erreur.');
            }
            $this->client->request('GET', $this->url($this->free));
            self::assertSelectorExists('.course-prerequisite-notice a[href="/dictees/tentatives/'.$state['attemptId'].'"]');
            self::assertSelectorNotExists('form[action="/course/confirmation/'.$this->free->getId().'"]');
            $session = $this->client->getSession();
            $session->set('_csrf/lesson_toggle_'.$this->free->getId(), 'free-valid-token');
            $session->save();
            $this->client->request('POST', '/course/confirmation/'.$this->free->getId(), ['_token' => 'free-valid-token']);
            self::assertResponseStatusCodeSame(403);
            $this->client->request('POST', '/course/'.$this->free->getId().'/bookmark');
            self::assertResponseStatusCodeSame(403);
            self::assertSame(0, (int) self::$database->manager()->getConnection()->fetchOne('SELECT COUNT(*) FROM lesson WHERE user_id = ?', [$this->user->getId()]));
        }
        $this->phase(DictationAction::Close, 1, 'Erreur.');
        $crawler = $this->client->request('GET', $this->url($this->free));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.course-prerequisite-notice');
        self::assertStringNotContainsString('Découvrir l’offre', $crawler->text());
        self::assertSelectorExists('form[name="comment_form"]');
        $this->client->request('POST', '/course/confirmation/'.$this->free->getId(), ['_token' => 'free-valid-token']);
        self::assertResponseRedirects($this->url($this->free));
        self::assertSame(1, (int) self::$database->manager()->getConnection()->fetchOne('SELECT COUNT(*) FROM lesson WHERE user_id = ?', [$this->user->getId()]));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function freeNoticeAccessCases(): iterable
    {
        yield 'visitor' => ['anonymous', true];
        yield 'no subscription' => ['unsubscribed', true];
        yield 'expired subscription' => ['expired', true];
        yield 'legacy without history' => ['legacy', false];
        yield 'feature disabled' => ['disabled', false];
        yield 'administrator without subscription' => ['admin', false];
    }

    #[DataProvider('freeNoticeAccessCases')]
    public function testFreeCourseNoticePreservesOtherAccessReasons(string $mode, bool $commercial): void
    {
        $em = self::$database->manager();
        $user = $em->find(User::class, $this->user->getId());
        if (in_array($mode, ['unsubscribed', 'expired', 'admin'], true)) {
            foreach ($user->getSubscriptions() as $subscription) {
                if ('expired' === $mode) {
                    $subscription->setStatus(SubscriptionStatus::EXPIRED);
                } else {
                    $user->removeSubscription($subscription);
                    $em->remove($subscription);
                }
            }
        }
        if ('admin' === $mode) {
            $user->setRoles(['ROLE_ADMIN']);
        }
        $em->flush();
        if ('legacy' === $mode) {
            $em->getConnection()->executeStatement('UPDATE user SET dictation_prerequisites = NULL WHERE id = ?', [$user->getId()]);
            $em->refresh($user);
        }
        self::ensureKernelShutdown();
        if ('disabled' === $mode) {
            $_ENV['DICTATION_LEARNER_ENABLED'] = $_SERVER['DICTATION_LEARNER_ENABLED'] = '0';
        }
        $this->client = self::createClient();
        if ('anonymous' !== $mode) {
            $this->client->loginUser($user);
        }
        $crawler = $this->client->request('GET', $this->url($this->free));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('CONTENU LIBRE', $crawler->text());
        self::assertSelectorNotExists('.course-prerequisite-notice');
        if ($commercial) {
            self::assertStringContainsString('Découvrir l’offre', $crawler->text());
            self::assertSelectorNotExists('form[name="comment_form"]');
            if ('anonymous' === $mode) {
                self::assertGreaterThan(0, $crawler->filter('.course-discovery-actions')->selectLink('Se connecter')->count());
            }
        } else {
            self::assertStringNotContainsString('Découvrir l’offre', $crawler->text());
            self::assertSelectorExists('form[name="comment_form"]');
            self::assertSelectorExists('form[action="/course/confirmation/'.$this->free->getId().'"]');
        }
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM lesson WHERE user_id = ?', [$user->getId()]));
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM dictation_attempt WHERE user_id = ?', [$user->getId()]));
    }

    public function testSearchPrivateMediaAndProgressCannotBypassPrerequisite(): void
    {
        $crawler = $this->client->request('GET', $this->sectionUrl());
        $autocomplete = html_entity_decode($crawler->filter('[data-symfony--ux-autocomplete--autocomplete-url-value]')->attr('data-symfony--ux-autocomplete--autocomplete-url-value'));
        $form = $crawler->filter('form[name="course_search"]')->form();
        $values = $form->getPhpValues();
        $values['course_search']['course'] = (string) $this->course->getId();
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects($this->url());
        $this->client->followRedirect();
        self::assertResponseRedirects('/dictees/'.$this->dictationId);
        $this->client->request('GET', $autocomplete.(str_contains($autocomplete, '?') ? '&' : '?').'query=Cours');
        self::assertResponseIsSuccessful();
        $json = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Cours requis', $json);
        foreach (['SECRET_PRIVATE_CONTENT', 'SECRET_COURSE_EXCERPT', 'SECRET_CORRECTION_TEST', 'canonicalText', 'variants'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        $this->client->request('GET', '/course/details/'.$this->course->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('/dictees/'.$this->dictationId, json_decode($this->client->getResponse()->getContent(), true)['url']);
        foreach (['GET', 'HEAD'] as $method) {
            $this->client->request($method, '/courses/'.$this->course->getId().'/media/audio', [], [], ['HTTP_RANGE' => 'bytes=0-1']);
            self::assertResponseStatusCodeSame(403);
        }
        $this->client->request('POST', '/course/confirmation/'.$this->course->getId(), ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/course/'.$this->course->getId().'/quiz');
        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame(0, (int) self::$database->manager()->getConnection()->fetchOne('SELECT COUNT(*) FROM lesson WHERE user_id = ?', [$this->user->getId()]));
    }

    public function testDisabledFeatureAndLegacyAccessSurviveWithoutInventedHistory(): void
    {
        $em = self::$database->manager();
        $legacy = $em->find(User::class, $this->user->getId());
        // Simulates the real migration default, not a fabricated Lesson/history.
        $em->getConnection()->executeStatement('UPDATE user SET dictation_prerequisites = NULL WHERE id = ?', [$legacy->getId()]);
        $em->refresh($legacy);
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->loginUser($legacy);
        $this->client->request('GET', $this->url());
        self::assertResponseIsSuccessful();
        self::assertFalse((new DictationJourney($em, true))->isLocked($legacy, $this->course->getSection()));
        $em->getConnection()->executeStatement('UPDATE user SET dictation_prerequisites = ? WHERE id = ?', [json_encode([$this->dictationId]), $legacy->getId()]);
        $em->refresh($legacy);
        self::assertTrue((new DictationJourney($em, true))->isLocked($legacy, $this->course->getSection()));
        self::ensureKernelShutdown();
        $_ENV['DICTATION_LEARNER_ENABLED'] = $_SERVER['DICTATION_LEARNER_ENABLED'] = '0';
        $this->client = self::createClient();
        $this->client->loginUser($legacy);
        $crawler = $this->client->request('GET', $this->sectionUrl());
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.dictation-journey-entry')->count());
        $this->client->request('GET', $this->url());
        self::assertResponseIsSuccessful();
    }

    public function testPrerequisiteNeverGrantsSubscriptionRightsAndAdminRemainsAllowed(): void
    {
        $crawler = $this->client->request('GET', $this->sectionUrl());
        $autocomplete = html_entity_decode($crawler->filter('[data-symfony--ux-autocomplete--autocomplete-url-value]')->attr('data-symfony--ux-autocomplete--autocomplete-url-value'));
        $em = self::$database->manager();
        $user = $em->find(User::class, $this->user->getId());
        foreach ($user->getSubscriptions() as $subscription) {
            $subscription->setStatus(SubscriptionStatus::EXPIRED);
        }
        $em->flush();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->loginUser($user);
        $this->client->request('GET', $this->url());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/dictees/'.$this->dictationId);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/course/details/'.$this->course->getId());
        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/json');
        $this->client->request('GET', $autocomplete.(str_contains($autocomplete, '?') ? '&' : '?').'query=Cours');
        self::assertResponseStatusCodeSame(403);
        $user->setRoles(['ROLE_ADMIN']);
        $em->flush();
        $this->client->loginUser($user);
        $this->client->request('GET', $this->url());
        self::assertResponseIsSuccessful();
    }

    public function testValidProgressTokenIsRejectedUntilClosure(): void
    {
        $session = $this->client->getSession();
        $session->set('_csrf/lesson_toggle_'.$this->course->getId(), 'lot6-valid-token');
        $session->save();
        $url = '/course/confirmation/'.$this->course->getId();
        $this->client->request('POST', $url, ['_token' => 'lot6-valid-token']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) self::$database->manager()->getConnection()->fetchOne('SELECT COUNT(*) FROM lesson WHERE user_id = ?', [$this->user->getId()]));
        $this->phase(DictationAction::Evaluate, 0, 'L’école ouvre.');
        $this->client->request('POST', $url, ['_token' => 'lot6-valid-token']);
        self::assertResponseRedirects($this->url());
        self::assertSame(1, (int) self::$database->manager()->getConnection()->fetchOne('SELECT COUNT(*) FROM lesson WHERE user_id = ?', [$this->user->getId()]));
    }

    public function testPrivateMediaChecksPrecedeRangesAndConditionals(): void
    {
        $em = self::$database->manager();
        $course = $em->find(Courses::class, $this->course->getId());
        $course->setContentType(\App\Enum\CourseContentType::Audio)->setAudioFileName('lot6.mp3');
        $em->flush();
        $root = $_SERVER['COURSE_STORAGE_DIR'];
        if (!is_dir($root.'/audios')) {
            mkdir($root.'/audios');
        }
        file_put_contents($root.'/audios/lot6.mp3', 'SYNTHETIC_PRIVATE_MEDIA');
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->loginUser($this->user);
        $url = '/courses/'.$course->getId().'/media/audio';
        foreach ([[], ['HTTP_RANGE' => 'bytes=0-3'], ['HTTP_IF_NONE_MATCH' => '*'], ['HTTP_IF_MODIFIED_SINCE' => 'Wed, 01 Jan 2031 00:00:00 GMT']] as $headers) {
            $this->client->request('GET', $url, server: $headers);
            self::assertResponseStatusCodeSame(403);
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE_MEDIA', $this->client->getResponse()->getContent());
        }
        $this->phase(DictationAction::Evaluate, 0, 'L’école ouvre.');
        $this->client->request('GET', $url, server: ['HTTP_RANGE' => 'bytes=0-3']);
        self::assertResponseStatusCodeSame(206);
        self::assertSame('SYNT', $this->client->getInternalResponse()->getContent());
    }

    public function testCreationWhileDisabledNeverRetroactivelyEnrollsOnActivation(): void
    {
        self::ensureKernelShutdown();
        $_ENV['DICTATION_LEARNER_ENABLED'] = $_SERVER['DICTATION_LEARNER_ENABLED'] = '0';
        $this->client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $old = QuizPlayerFactory::user('disabled-'.bin2hex(random_bytes(5)).'@example.test');
        $em->persist($old);
        $em->flush();
        self::assertNull($old->getDictationPrerequisites());
        self::ensureKernelShutdown();
        $_ENV['DICTATION_LEARNER_ENABLED'] = $_SERVER['DICTATION_LEARNER_ENABLED'] = '1';
        $this->client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $old = $em->find(User::class, $old->getId());
        $old->setFirstname('Compte conservé');
        $em->flush();
        self::assertNull($old->getDictationPrerequisites());
        self::assertFalse((new DictationJourney($em, true))->isLocked($old, $this->course->getSection()));
        $this->expectException(\LogicException::class);
        $old->enrollDictationJourney([$this->dictationId]);
    }

    public function testLinkedExercisesCommentsAndBookmarksCannotBypassTheGate(): void
    {
        $em = self::$database->manager();
        $user = $em->find(User::class, $this->user->getId());
        $course = $em->find(Courses::class, $this->course->getId());
        $exercise = (new \App\Entity\Exercice())->setTitle('Exercice privé')->setInstruction('SECRET_EXERCISE')->setData(['sentences' => []]);
        $course->setExercice($exercise);
        $comment = (new \App\Entity\Comment())->setUser($user)->setCourse($course)->setContent('SECRET_COMMENT');
        $em->persist($exercise);
        $em->persist($comment);
        $em->flush();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->loginUser($user);
        foreach (['/exercise/'.$exercise->getId().'/submit', '/comments/'.$comment->getId().'/like'] as $url) {
            $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
            self::assertResponseStatusCodeSame(403);
            self::assertResponseHeaderSame('content-type', 'application/json');
            self::assertStringNotContainsString('SECRET_', $this->client->getResponse()->getContent());
        }
        $this->client->request('GET', '/exercise/'.$exercise->getId());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/course/'.$course->getId().'/bookmark');
        self::assertResponseStatusCodeSame(403);
        self::assertNull($em->getConnection()->fetchOne('SELECT last_visited_course_id FROM user WHERE id = ?', [$user->getId()]));
    }

    public function testFirstPublicationAfterEnrollmentDoesNotLockPreviouslyAvailableSection(): void
    {
        $em = self::$database->manager();
        $user = $em->find(User::class, $this->user->getId());
        $section = $em->find(Sections::class, $this->foundation->getSection()->getId());
        $dictation = new \App\Entity\Dictation($section);
        $version = new \App\Entity\DictationVersion($dictation, 1, 'Publication tardive', 'Texte tardif.');
        $em->persist($dictation);
        $em->persist($version);
        $em->flush();
        self::assertNull((new DictationJourney($em, true))->forSection($user, $section));
        $this->client->request('GET', $this->url($this->foundation));
        self::assertResponseIsSuccessful();
        \App\Tests\Support\DictationAudioFixture::prepare($version);
        $version->publish(new \App\Services\Dictation\DictationCorrector(), \App\Tests\Support\DictationAudioFixture::storage());
        $em->persist($dictation);
        $em->persist($version);
        $em->flush();
        self::assertFalse((new DictationJourney($em, true))->isLocked($user, $section));
        $this->client->request('GET', $this->url($this->foundation));
        self::assertResponseIsSuccessful();
    }

    public function testAfterCoursesEntryUsesAllLessonsAndKeepsGetReadOnly(): void
    {
        $state = $this->phase(DictationAction::Evaluate, 0, 'L’école ouvre.');
        $crawler = $this->client->request('GET', $this->sectionUrl());
        self::assertSame(0, $crawler->filter('[data-dictation-position="after"] a')->count());
        $em = self::$database->manager();
        $user = $em->find(User::class, $this->user->getId());
        foreach ([$this->course, $this->free] as $course) {
            $em->persist((new Lesson())->setUser($user)->setCourse($em->find(Courses::class, $course->getId()))->setName('Validé')->setStatus(LessonStatus::DONE));
            $em->flush();
            $crawler = $this->client->request('GET', $this->sectionUrl());
            if ($course === $this->course) {
                self::assertSame(0, $crawler->filter('[data-dictation-position="after"] a')->count());
            }
        }
        self::assertGreaterThanOrEqual(3, $crawler->filter('[data-dictation-position="after"] a')->count());
        $this->client->request('GET', '/dictees/tentatives/'.$state['attemptId']);
        self::assertResponseIsSuccessful();
        self::assertSame('awaiting_courses', $this->phase()['state']);
        self::assertSame('reviewing', $this->phase(DictationAction::Resume, 1)['state']);
        self::assertSame('completed', $this->phase(DictationAction::Finalize, 2)['state']);
        $crawler = $this->client->request('GET', $this->sectionUrl());
        self::assertStringContainsString('Consulter le résultat définitif', $crawler->text());
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function presentationCases(): iterable
    {
        foreach ([false, true] as $exempt) {
            foreach ([false, true] as $empty) {
                foreach ([false, true] as $perfect) {
                    yield ($exempt ? 'exempt' : 'required').'-'.($empty ? 'empty' : 'courses').'-'.($perfect ? 'perfect' : 'errors') => [$exempt, $empty, $perfect];
                }
            }
        }
    }

    #[DataProvider('presentationCases')]
    public function testJourneyPresentationFollowsActualStagesAndCourseAvailability(bool $exempt, bool $empty, bool $perfect): void
    {
        $em = self::$database->manager();
        $user = $em->find(User::class, $this->user->getId());
        $section = $em->find(Sections::class, $this->course->getSection()->getId());
        if ($exempt) {
            $em->getConnection()->executeStatement('UPDATE user SET dictation_prerequisites = NULL WHERE id = ?', [$user->getId()]);
            $em->refresh($user);
        }
        if ($empty) {
            foreach ([$this->course, $this->free] as $course) {
                $em->remove($em->find(Courses::class, $course->getId()));
            }
            $em->flush();
        }
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/dictees/'.$this->dictationId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Cette première dictée vous aide à repérer vos points à travailler. Aucun score minimum n’est demandé : vos erreurs sont le point de départ de votre progression.');
        self::assertSame(1, substr_count($crawler->text(), 'Aucun score minimum'));
        self::assertSame(!$exempt, str_contains($crawler->filter('main')->text(), 'pour accéder aux cours privés'));
        $waitingText = 'Vous pourrez reprendre votre dictée après la mise à disposition et la validation des cours de cette section.';
        foreach (['/courses/'.$section->getProgram()->getSlug(), $this->sectionUrl()] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $initial = $crawler->filter('[data-dictation-position="before"]');
            self::assertGreaterThanOrEqual(3, $initial->count()); // Main content, desktop sidebar, mobile sidebar.
            self::assertSame($initial->count(), $initial->filter('.list-group-icon[aria-hidden="true"] .bi-pencil-square')->count());
            self::assertSame(0, $crawler->filter('.dictation-journey-entry .completed')->count());
            self::assertSame($exempt, str_contains($initial->text(), 'Conseillée pour repérer vos points à travailler.'));
            self::assertSame(!$exempt, str_contains($initial->text(), 'Avant les cours de cette section'));
            self::assertSame($empty, str_contains($crawler->text(), $waitingText));
            self::assertSelectorTextContains('[data-dictation-position="after"]', 'Clôturez également la dictée initiale.');
            self::assertSelectorExists('[data-dictation-position="after"] [aria-disabled="true"] .bi-arrow-repeat');
            self::assertSelectorNotExists('[data-dictation-position="after"] a');
        }
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM dictation_attempt WHERE user_id = ?', [$user->getId()]));
        $this->phase(DictationAction::Evaluate, 0, $perfect ? 'L’école ouvre.' : 'Erreur.');
        if (!$perfect) {
            $this->client->request('GET', $this->sectionUrl());
            self::assertSelectorNotExists('.dictation-journey-entry .completed');
            $this->phase(DictationAction::Close, 1, 'Erreur.');
        }
        $crawler = $this->client->request('GET', $this->sectionUrl());
        self::assertSelectorExists('[data-dictation-position="before"] .completed svg[aria-hidden="true"][focusable="false"]');
        self::assertSelectorNotExists('[data-dictation-position="after"] .completed');
        self::assertSelectorNotExists('[data-dictation-position="after"] a');
        self::assertSelectorTextContains('[data-dictation-position="before"]', $empty ? 'Votre dictée initiale est terminée.' : 'Les cours sont accessibles');
        self::assertStringNotContainsString('Clôturez également', $crawler->text());
        if ($empty) {
            self::assertStringNotContainsString('Les cours sont accessibles', $crawler->text());
            self::assertStringContainsString($waitingText, $crawler->text());
            $courses = [];
            for ($i = 1; $i <= 2; ++$i) {
                $course = (new Courses())->setSection($section)->setName('Cours ajouté '.$i)->setSlug('added-'.$i)->setPosition($i);
                $em->persist($course);
                $em->flush();
                $courses[] = $course;
                $crawler = $this->client->request('GET', $this->sectionUrl());
                self::assertStringNotContainsString($waitingText, $crawler->text());
                self::assertSelectorTextContains('[data-dictation-position="before"]', 'Les cours sont accessibles');
                self::assertSelectorTextContains('[data-dictation-position="after"]', 'Validez tous les cours');
                self::assertSelectorExists('.main-content .card ol > li:first-child[data-dictation-position="before"]');
                self::assertSelectorExists('.main-content .card ol > li:last-child[data-dictation-position="after"]');
                self::assertSelectorNotExists('[data-dictation-position="after"] a');
            }
        } else {
            $courses = [$em->find(Courses::class, $this->course->getId()), $em->find(Courses::class, $this->free->getId())];
        }
        foreach ($courses as $index => $course) {
            $em->persist((new Lesson())->setUser($user)->setCourse($course)->setName('Validé')->setStatus(LessonStatus::DONE));
            $em->flush();
            $this->client->request('GET', $this->sectionUrl());
            if (0 === $index) {
                self::assertSelectorNotExists('[data-dictation-position="after"] a');
            }
        }
        self::assertSelectorExists('[data-dictation-position="after"] a .bi-arrow-repeat');
        self::assertSelectorNotExists('[data-dictation-position="after"] .completed');
        $revision = $perfect ? 1 : 2;
        $state = $this->phase(DictationAction::Resume, $revision);
        self::assertSame($perfect ? 'reviewing' : 'resuming', $state['state']);
        $this->client->request('GET', $this->sectionUrl());
        self::assertSelectorNotExists('[data-dictation-position="after"] .completed');
        $this->phase(DictationAction::Finalize, $revision + 1, $perfect ? null : 'L’école ouvre.');
        foreach (['/courses/'.$section->getProgram()->getSlug(), $this->sectionUrl()] as $url) {
            $crawler = $this->client->request('GET', $url);
            $after = $crawler->filter('[data-dictation-position="after"]');
            self::assertSame($after->count(), $after->filter('.completed svg[aria-hidden="true"][focusable="false"]')->count());
            self::assertStringContainsString('Consulter le résultat définitif', $after->text());
        }
    }
}
