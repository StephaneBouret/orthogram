<?php

declare(strict_types=1);

namespace App\Tests\Controller\Course;

use App\Entity\Courses;
use App\Entity\DictationAttempt;
use App\Entity\DictationVersion;
use App\Entity\Lesson;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\LessonStatus;
use App\Enum\SubscriptionStatus;
use App\Enum\UserAccountStatus;
use App\Services\Dictation\DictationCorrector;
use App\Services\Dictation\DictationEditorialService;
use App\Services\Dictation\DictationTokenizer;
use App\Tests\Support\DictationAudioFixture;
use App\Tests\Support\DictationFactory;
use App\Tests\Support\DictationTestDatabase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class DictationControllerTest extends WebTestCase
{
    private static DictationTestDatabase $database;
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private DictationVersion $version;
    private string $startToken;
    /** @var array<string, array{mixed, mixed}> */
    private array $previous;

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
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(self::$database->name, $this->em->getConnection()->getDatabase());
        [$this->user, , $this->version] = DictationFactory::create($this->em);
        $subscription = (new Subscription())->setEmail($this->user->getEmail())->setStatus(SubscriptionStatus::ACTIVE)->setIsLifetime(true);
        $this->user->addSubscription($subscription);
        $this->em->persist($subscription);
        $this->em->flush();
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->close();
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

    private function entry(): string
    {
        return '/dictees/'.$this->version->getDictation()->getId();
    }

    /** @return array{string, string} */
    private function start(): array
    {
        $crawler = $this->client->request('GET', $this->entry());
        self::assertResponseIsSuccessful();
        $this->startToken = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->submit($crawler->selectButton('Commencer ou retrouver ma copie')->form());
        self::assertResponseRedirects(null, 303);
        $url = $this->client->getResponse()->headers->get('Location');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        return [$url, $crawler->filter('[data-dictation-writing-token-value]')->attr('data-dictation-writing-token-value')];
    }

    private function save(string $url, string $token, int $revision, string $copy): void
    {
        $this->client->request('POST', $url.'/enregistrer', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: json_encode(['revision' => $revision, 'copy' => $copy], JSON_THROW_ON_ERROR));
    }

    public function testUnicodeCountsReplayConflictAndReload(): void
    {
        [$url, $token] = $this->start();
        $copy = "😀 L’e\u{0301}cole ouvre.\nœ porte-monnaie\u{00a0}…";
        $this->save($url, $token, 0, $copy);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['revision' => 1, 'wordCount' => (new DictationTokenizer())->countWords($copy), 'referenceWordCount' => 3], $data);
        $this->save($url, $token, 0, $copy);
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(json_encode($data, JSON_THROW_ON_ERROR), $this->client->getResponse()->getContent());
        $this->save($url, $token, 0, 'Autre onglet');
        self::assertResponseStatusCodeSame(409);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        // HTML parsers discard the first LF after <textarea>; the template supplies that LF.
        self::assertSame($copy, $this->client->getCrawler()->filter('#dictation-copy')->text(null, false));
        self::assertSelectorTextContains('#dictation-count', '5 / 3');
    }

    public function testGetDoesNotCreateCsrfAndActionAllowlist(): void
    {
        $this->client->request('GET', $this->entry());
        self::assertSame(0, $this->em->getRepository(DictationAttempt::class)->count(['user' => $this->user]));
        $this->client->request('GET', $this->entry().'/commencer');
        self::assertResponseStatusCodeSame(405);
        $this->client->request('POST', $this->entry().'/commencer');
        self::assertResponseStatusCodeSame(403);
        [$url, $token] = $this->start();
        $this->save($url, 'invalid', 0, 'Texte');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $url.'/enregistrer', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: '{"revision":0,"copy":"Texte","action":"evaluate"}');
        self::assertResponseStatusCodeSame(422);
        foreach (['evaluate', 'close', 'resume', 'finalize'] as $action) {
            $this->client->request('POST', $url.'/'.$action);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testPinnedVersionUniquenessAndConfidentiality(): void
    {
        [$url, $token] = $this->start();
        $html = $this->client->getResponse()->getContent();
        foreach (['L’école ouvre.', 'SECRET_CORRECTION_TEST', 'canonicalText', 'acceptedVariants', 'scorePercent', 'initialFeedback', 'sha256', 'durationMs'] as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
        $other = self::$database->manager();
        $source = $other->find(DictationVersion::class, $this->version->getId());
        $editor = new DictationEditorialService($other, new DictationCorrector(), DictationAudioFixture::storage());
        $next = $editor->cloneVersion($source, $source->getRevision());
        $next->edit('Nouvelle version', 'Une autre dictée.', null);
        $editor->saveDraft($next, $next->getRevision());
        $editor->publish($next, $next->getRevision());
        $this->client->request('GET', $this->entry());
        self::assertResponseRedirects($url);
        $this->client->request('POST', $this->entry().'/commencer', ['_token' => $this->startToken]);
        self::assertResponseRedirects($url, 303);
        $attempts = $other->getRepository(DictationAttempt::class)->findBy(['user' => $this->user->getId()]);
        self::assertCount(1, $attempts);
        self::assertSame($source->getId(), $attempts[0]->getVersion()->getId());
        $this->save($url, $token, 0, 'Saisie');
        self::assertSame(['revision', 'wordCount', 'referenceWordCount'], array_keys(json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)));
        $other->close();
    }

    public function testPrivateMediaRangeHeadAndForeignOwnership(): void
    {
        [$url, $token] = $this->start();
        foreach (['complete', 'writing'] as $role) {
            $this->client->request('HEAD', $url.'/audio/'.$role);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'audio/mpeg');
            $this->client->request('GET', $url.'/audio/'.$role, server: ['HTTP_RANGE' => 'bytes=0-15']);
            self::assertResponseStatusCodeSame(206);
            self::assertInstanceOf(BinaryFileResponse::class, $this->client->getResponse());
            self::assertSame(16, strlen($this->client->getInternalResponse()->getContent()));
            self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        }
        $other = self::$database->manager();
        [$foreign] = DictationFactory::create($other);
        $foreign->setRoles(['ROLE_ADMIN']);
        $other->flush();
        $this->client->loginUser($foreign);
        foreach (['GET', 'HEAD'] as $method) {
            $this->client->request($method, $url.'/audio/writing', server: ['HTTP_RANGE' => 'bytes=0-15']);
            self::assertResponseStatusCodeSame(404);
        }
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
        $this->save($url, $token, 0, 'Interdit');
        self::assertResponseStatusCodeSame(404);
        $other->close();
    }

    #[DataProvider('accessCases')]
    public function testAccessGate(string $mode, int $status): void
    {
        [$url, $token] = $this->start();
        $other = self::$database->manager();
        $user = $other->find(User::class, $this->user->getId());
        if ('disabled' === $mode) {
            $_ENV['DICTATION_LEARNER_ENABLED'] = $_SERVER['DICTATION_LEARNER_ENABLED'] = '0';
        }
        if ('anonymous' === $mode) {
            $this->client->getCookieJar()->clear();
        }
        if ('inactive' === $mode) {
            $user->setAccountStatus(UserAccountStatus::INACTIVE);
        }
        if ('subscription' === $mode) {
            foreach ($user->getSubscriptions() as $subscription) {
                $subscription->setStatus(SubscriptionStatus::EXPIRED);
            }
        }
        $other->flush();
        if ('inactive' === $mode) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/login');
        }
        if ('2fa' === $mode) {
            $session = $this->client->getSession();
            $session->set('_security_main', serialize(new TwoFactorToken(new UsernamePasswordToken($user, 'main', $user->getRoles()), null, 'main', ['email'])));
            $session->save();
        }
        foreach ([$this->entry(), $url, $url.'/audio/complete', $url.'/audio/writing'] as $route) {
            $this->client->request('GET', $route);
            self::assertResponseStatusCodeSame($status);
        }
        $this->client->request('HEAD', $url.'/audio/complete', server: ['HTTP_RANGE' => 'bytes=0-15']);
        self::assertResponseStatusCodeSame($status);
        $this->save($url, $token, 0, 'Refusé');
        self::assertResponseStatusCodeSame($status);
        foreach (['evaluer', 'terminer', 'reprendre', 'finaliser'] as $action) {
            $this->submitTransition($url, $action, $token, 0, 'Refusé');
            self::assertResponseStatusCodeSame($status);
        }
        $this->client->request('POST', $this->entry().'/commencer');
        self::assertResponseStatusCodeSame($status);
        $other->close();
    }

    /** @return iterable<string, array{string, int}> */
    public static function accessCases(): iterable
    {
        yield 'disabled' => ['disabled', 404];
        yield 'anonymous' => ['anonymous', 401];
        yield 'inactive' => ['inactive', 401]; // The existing firewall removes inactive sessions.
        yield 'no training access' => ['subscription', 403];
        yield 'incomplete two factor' => ['2fa', 302]; // Existing firewall redirects to the second factor.
    }

    public function testLimitsRejectWithoutChangingDraft(): void
    {
        [$url, $token] = $this->start();
        $this->save($url, $token, 0, str_repeat('a ', 751));
        self::assertResponseStatusCodeSame(422);
        $this->save($url, $token, 0, str_repeat('😀', 10001));
        self::assertResponseStatusCodeSame(422);
        $this->save($url, $token, 0, 'Valide');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $url);
        self::assertSelectorTextSame('#dictation-copy', 'Valide');
    }

    public function testLegacyPublishedVersionWithoutMediaDoesNotCreateAttempt(): void
    {
        // Reproduce a pre-2B row only inside the disposable database.
        $this->em->getConnection()->executeStatement('UPDATE dictation_version SET complete_audio = NULL WHERE id = ?', [$this->version->getId()]);
        $crawler = $this->client->request('GET', $this->entry());
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Commencer ou retrouver ma copie')->form());
        self::assertResponseStatusCodeSame(409);
        $other = self::$database->manager();
        self::assertSame(0, $other->getRepository(DictationAttempt::class)->count(['user' => $this->user->getId()]));
        $other->close();
    }

    private function submitTransition(string $url, string $action, string $token, int $revision, ?string $copy): void
    {
        $this->client->request('POST', $url.'/'.$action, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: json_encode(['revision' => $revision, 'copy' => $copy], JSON_THROW_ON_ERROR));
    }

    private function transitionToken(): string
    {
        return $this->client->getCrawler()->filter('[data-dictation-writing-transition-token-value]')->attr('data-dictation-writing-transition-token-value');
    }

    private function lessons(bool $done): void
    {
        $em = self::$database->manager();
        $user = $em->find(User::class, $this->user->getId());
        $courses = $em->createQueryBuilder()->select('c')->from(Courses::class, 'c')
            ->where('c.section = :section')->setParameter('section', $this->version->getDictation()->getSection()->getId())->getQuery()->getResult();
        foreach ($courses as $course) {
            $lesson = $em->createQueryBuilder()->select('l')->from(Lesson::class, 'l')
                ->where('l.user = :user AND l.course = :course')->setParameter('user', $user)->setParameter('course', $course)->getQuery()->getOneOrNullResult()
                ?? (new Lesson())->setName('Validation isolée')->setUser($user)->setCourse($course);
            $lesson->setStatus($done ? LessonStatus::DONE : LessonStatus::STUDY);
            $em->persist($lesson);
        }
        $em->flush();
        $em->close();
    }

    private function phaseToken(): string
    {
        return $this->client->getCrawler()->filter('[data-dictation-phase-token-value]')->attr('data-dictation-phase-token-value');
    }

    #[DataProvider('postCourseCases')]
    public function testPostCourseJourney(bool $initialPerfect, string $closed, bool $reviewing, bool $explanations): void
    {
        if (!$explanations) {
            $editor = new DictationEditorialService($this->em, new DictationCorrector(), DictationAudioFixture::storage());
            $next = $editor->cloneVersion($this->version, $this->version->getRevision());
            $next->edit('Sans explications', 'L’école ouvre.', null);
            $editor->saveDraft($next, $next->getRevision());
            $editor->publish($next, $next->getRevision());
        }
        [$url, $saveToken] = $this->start();
        $evaluateToken = $this->transitionToken();
        $initial = $initialPerfect ? 'L’école ouvre.' : 'L’ ouvre.';
        $this->submitTransition($url, 'evaluer', $evaluateToken, 0, $initial);
        self::assertResponseIsSuccessful();
        $revision = 1;
        if (!$initialPerfect) {
            $this->client->request('GET', $url);
            $this->submitTransition($url, 'terminer', $this->transitionToken(), $revision++, $closed);
            self::assertResponseIsSuccessful();
        }
        $this->client->request('GET', $url);
        self::assertSelectorNotExists('[data-controller="dictation-phase"]');
        self::assertSelectorTextContains('main', 'après validation');
        $this->lessons(false); // STUDY/visits do not unlock Resume.
        $this->client->request('GET', $url);
        self::assertSelectorNotExists('[data-controller="dictation-phase"]');
        $this->lessons(true);
        $this->client->request('GET', $url);
        $resumeToken = $this->phaseToken();
        self::assertSelectorExists('#dictation-closed-copy'); // GET never resumes.
        $this->lessons(false);
        $this->submitTransition($url, 'reprendre', $resumeToken, $revision, null);
        self::assertResponseStatusCodeSame(409);
        $this->lessons(true);
        foreach (['reprendre', 'finaliser'] as $action) {
            $this->client->request('GET', $url.'/'.$action);
            self::assertResponseStatusCodeSame(405);
            $this->submitTransition($url, $action, 'invalid', $revision, null);
            self::assertResponseStatusCodeSame(403);
        }
        $this->submitTransition($url, 'reprendre', $resumeToken, $revision, null);
        self::assertResponseIsSuccessful();
        $resumeRevision = $revision++;
        $this->submitTransition($url, 'reprendre', $resumeToken, $resumeRevision, null);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $url);
        $finalToken = $reviewing ? $this->phaseToken() : $this->transitionToken();
        self::assertSelectorNotExists('[data-dictation-writing-issues-value]');
        self::assertSelectorNotExists('#dictation-corrected-text');
        self::assertStringNotContainsString('SECRET_CORRECTION_TEST', $this->client->getResponse()->getContent());
        if ($reviewing) {
            self::assertSelectorNotExists('#dictation-copy');
            self::assertSelectorTextContains('main', 'sans nouvelle saisie');
            $finalCopy = null;
            $this->submitTransition($url, 'finaliser', $finalToken, $revision, 'Copie interdite');
            self::assertResponseStatusCodeSame(422);
        } else {
            self::assertSelectorTextSame('#dictation-copy', $closed);
            self::assertSelectorTextContains('label[for="dictation-copy"]', 'Votre dictée après les cours');
            $this->client->request('HEAD', $url.'/audio/writing');
            self::assertResponseIsSuccessful();
            $this->save($url, $saveToken, $revision, 'Brouillon après cours');
            self::assertResponseIsSuccessful();
            $this->save($url, $saveToken, $revision++, 'Brouillon après cours');
            self::assertResponseIsSuccessful();
            $this->client->request('GET', $url);
            self::assertSelectorTextSame('#dictation-copy', 'Brouillon après cours');
            $finalCopy = "Erreur restante.\n<script>copie</script>";
            $this->submitTransition($url, 'finaliser', $finalToken, $revision - 1, $finalCopy);
            self::assertResponseStatusCodeSame(409);
        }
        // A new publication cannot replace the version attached to this attempt.
        $other = self::$database->manager();
        $attempt = $other->getRepository(DictationAttempt::class)->findOneBy(['user' => $this->user->getId()]);
        $source = $attempt->getVersion();
        $editor = new DictationEditorialService($other, new DictationCorrector(), DictationAudioFixture::storage());
        $next = $editor->cloneVersion($source, $source->getRevision());
        $next->edit('Nouvelle publication', 'NOUVELLE_REFERENCE.', 'NOUVELLES_EXPLICATIONS');
        $editor->saveDraft($next, $next->getRevision());
        $editor->publish($next, $next->getRevision());
        $other->close();
        $this->lessons(false);
        $this->submitTransition($url, 'finaliser', $finalToken, $revision, $finalCopy);
        self::assertResponseStatusCodeSame(409);
        $this->lessons(true);
        $this->submitTransition($url, 'finaliser', $finalToken, $revision, $finalCopy);
        self::assertResponseIsSuccessful();
        $this->submitTransition($url, 'finaliser', $finalToken, $revision, $finalCopy);
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(json_encode(['revision' => $revision + 1, 'state' => 'completed'], JSON_THROW_ON_ERROR), $this->client->getResponse()->getContent());
        $this->submitTransition($url, 'evaluer', $evaluateToken, 0, $initial);
        self::assertResponseIsSuccessful();
        self::assertSame(['revision', 'state'], array_keys(json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)));
        $this->client->request('GET', $url);
        self::assertSelectorTextSame('#dictation-corrected-text', 'L’école ouvre.');
        self::assertSame($finalCopy ?? $closed, $this->client->getCrawler()->filter('#dictation-final-copy')->text(null, false));
        self::assertSelectorNotExists('#dictation-final-copy script');
        self::assertSelectorNotExists('[data-dictation-writing-issues-value]');
        self::assertSame($explanations ? 1 : 0, $this->client->getCrawler()->filter('#dictation-explanations')->count());
        self::assertStringNotContainsString('NOUVELLE_REFERENCE', $this->client->getResponse()->getContent());
        $summary = $this->client->getCrawler()->filter('.dictation-summary')->text();
        $this->client->request('GET', $url);
        self::assertSelectorTextSame('.dictation-summary', $summary);
        $this->save($url, $saveToken, $revision + 1, 'Interdit');
        self::assertResponseStatusCodeSame(409);
        $this->submitTransition($url, 'evaluer', $evaluateToken, $revision + 1, 'Interdit');
        self::assertResponseStatusCodeSame(409);
        $this->client->request('GET', $url.'/audio/writing');
        self::assertResponseStatusCodeSame(409);
        $this->lessons(false);
        foreach ([['reprendre', $resumeToken, $resumeRevision, null], ['finaliser', $finalToken, $revision, $finalCopy]] as [$action, $token, $r, $copy]) {
            $this->submitTransition($url, $action, $token, $r, $copy);
            self::assertResponseStatusCodeSame(409); // Even exact receipts recheck current completion.
        }
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#dictation-corrected-text');
        self::assertSelectorNotExists('#dictation-explanations');
        self::assertSelectorTextSame('.dictation-summary', $summary);
        $other = self::$database->manager();
        $attempt = $other->getRepository(DictationAttempt::class)->findOneBy(['user' => $this->user->getId()]);
        self::assertSame($reviewing, null === $attempt->getFinalResult());
        self::assertSame($source->getId(), $attempt->getVersion()->getId());
        self::assertSame($revision + 1, $attempt->getRevision());
        [$foreign] = DictationFactory::create($other);
        $this->client->loginUser($foreign);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
        foreach (['reprendre', 'finaliser'] as $action) {
            $this->submitTransition($url, $action, $finalToken, $revision, null);
            self::assertResponseStatusCodeSame(404);
        }
        $other->close();
    }

    /** @return iterable<string, array{bool, string, bool, bool}> */
    public static function postCourseCases(): iterable
    {
        yield 'initial perfect' => [true, 'L’école ouvre.', true, true];
        yield 'self corrected perfect without explanations' => [false, 'L’école ouvre.', true, false];
        yield '100 percent with punctuation error' => [false, 'L’école ouvre', false, true];
        yield 'remaining spelling errors without explanations' => [false, 'L’ecole ouvre.', false, false];
    }

    public function testCorrectionEscapingProvenanceAndNewRequiredCourse(): void
    {
        $reference = "L’école ouvre.\n<b>Texte corrigé</b>";
        $explanation = "Une explication.\n<script>inert</script>";
        $editor = new DictationEditorialService($this->em, new DictationCorrector(), DictationAudioFixture::storage());
        $next = $editor->cloneVersion($this->version, $this->version->getRevision());
        $next->edit('Plusieurs lignes', $reference, $explanation);
        $editor->saveDraft($next, $next->getRevision());
        $editor->publish($next, $next->getRevision());
        [$url] = $this->start();
        $this->submitTransition($url, 'evaluer', $this->transitionToken(), 0, $reference);
        self::assertResponseIsSuccessful();
        $this->lessons(true);
        $this->client->request('GET', $url);
        $this->submitTransition($url, 'reprendre', $this->phaseToken(), 1, null);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $url);
        $this->submitTransition($url, 'finaliser', $this->phaseToken(), 2, null);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $url);
        self::assertSame($reference, $this->client->getCrawler()->filter('#dictation-corrected-text')->text(null, false));
        self::assertSame($explanation, $this->client->getCrawler()->filter('#dictation-explanations')->text(null, false));
        self::assertSelectorNotExists('#dictation-corrected-text b');
        self::assertSelectorNotExists('#dictation-explanations script');
        $other = self::$database->manager();
        $attempt = $other->getRepository(DictationAttempt::class)->findOneBy(['user' => $this->user->getId()]);
        $workflow = DictationFactory::service($other);
        $completed = $workflow->state($attempt->getUser(), $attempt->getId(), true);
        self::assertSame('initial_closure', $completed['finalResultSource']);
        $closed = $attempt->getClosedResult();
        unset($closed['issues']);
        self::assertSame($closed, $completed['finalResult']);
        self::assertSame($reference, $completed['canonicalText']);
        foreach (['initialFeedback', 'initialCopy', 'issues', 'variants', 'completeAudio', 'writingAudio', 'draft'] as $key) {
            self::assertArrayNotHasKey($key, $completed);
        }
        $course = (new Courses())->setName('Nouveau cours requis')->setSlug('required-'.bin2hex(random_bytes(6)))
            ->setContentType(\App\Enum\CourseContentType::Twig)->setSection($attempt->getDictation()->getSection());
        $other->persist($course);
        $other->flush();
        $hidden = $workflow->state($attempt->getUser(), $attempt->getId(), true);
        self::assertFalse($hidden['canConsultCorrection']);
        self::assertArrayNotHasKey('canonicalText', $hidden);
        self::assertArrayNotHasKey('correctionContent', $hidden);
        self::assertSame($completed['finalCopy'], $hidden['finalCopy']);
        self::assertSame($completed['finalResult'], $hidden['finalResult']);
        $this->client->request('GET', $url);
        self::assertSelectorNotExists('#dictation-corrected-text');
        self::assertStringNotContainsString('Une explication.', $this->client->getResponse()->getContent());
        $other->close();
        $this->lessons(true);
        $this->client->request('GET', $url);
        self::assertSelectorExists('#dictation-corrected-text');
        self::assertSelectorExists('#dictation-explanations');
    }

    public function testInitialJourneyFrozenFeedbackAndLateReceipts(): void
    {
        [$url, $saveToken] = $this->start();
        $evaluateToken = $this->transitionToken();
        $this->save($url, $saveToken, 0, 'Ancienne copie');
        $before = self::$database->manager();
        $savedAttempt = $before->getRepository(DictationAttempt::class)->findOneBy(['user' => $this->user->getId()]);
        self::assertNull($savedAttempt->getInitialResult());
        self::assertNull($savedAttempt->getClosedResult());
        $before->close();
        $copy = "😀 L’ ouvre.\n<script>alert(1)</script>";
        $this->submitTransition($url, 'evaluer', $evaluateToken, 1, $copy);
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"revision":2,"state":"self_correction"}', $this->client->getResponse()->getContent());
        // Exact receipt replay, and refusal of a different evaluation / stale SAVE.
        $this->submitTransition($url, 'evaluer', $evaluateToken, 1, $copy);
        self::assertResponseIsSuccessful();
        $this->submitTransition($url, 'evaluer', $evaluateToken, 2, 'Autre copie');
        self::assertResponseStatusCodeSame(409);
        $this->save($url, $saveToken, 1, 'Copie tardive');
        self::assertResponseStatusCodeSame(409);
        $this->client->request('GET', $url);
        $closeToken = $this->transitionToken();
        self::assertSelectorTextContains('label[for="dictation-copy"]', 'Votre autocorrection');
        self::assertSame($copy, $this->client->getCrawler()->filter('[data-dictation-writing-target="feedback"]')->text(null, false));
        $issues = $this->client->getCrawler()->filter('[data-dictation-writing-issues-value]')->attr('data-dictation-writing-issues-value');
        self::assertStringContainsString('&lt;script&gt;', $this->client->getResponse()->getContent());
        self::assertSelectorNotExists('[data-dictation-writing-target="feedback"] script');
        $this->save($url, $saveToken, 2, 'Autocorrection provisoire');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $url);
        self::assertSame($issues, $this->client->getCrawler()->filter('[data-dictation-writing-issues-value]')->attr('data-dictation-writing-issues-value'));
        self::assertSame($copy, $this->client->getCrawler()->filter('[data-dictation-writing-target="feedback"]')->text(null, false));
        $this->client->request('GET', $url.'/audio/writing');
        self::assertResponseIsSuccessful();
        $closed = 'Copie réellement soumise à la clôture.';
        $this->submitTransition($url, 'terminer', $closeToken, 3, $closed);
        self::assertResponseIsSuccessful();
        $this->submitTransition($url, 'terminer', $closeToken, 3, $closed);
        self::assertResponseIsSuccessful();
        $this->submitTransition($url, 'evaluer', $evaluateToken, 1, $copy);
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"revision":4,"state":"awaiting_courses"}', $this->client->getResponse()->getContent());
        $this->save($url, $saveToken, 3, 'Autocorrection provisoire');
        self::assertResponseStatusCodeSame(409);
        $this->save($url, $saveToken, 4, 'Même révision mais phase clôturée');
        self::assertResponseStatusCodeSame(409);
        $this->client->request('GET', $url.'/audio/writing');
        self::assertResponseStatusCodeSame(409);
        $this->client->request('GET', $url);
        self::assertSelectorTextSame('#dictation-closed-copy', $closed);
        foreach (['initialFeedback', 'issues-value', 'SECRET_CORRECTION_TEST', 'L’école ouvre.'] as $secret) {
            self::assertStringNotContainsString($secret, $this->client->getResponse()->getContent());
        }
        self::assertSelectorNotExists('#dictation-copy');
        self::assertSelectorExists('a[href^="/courses/"]');
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        $other = self::$database->manager();
        $attempt = $other->getRepository(DictationAttempt::class)->findOneBy(['user' => $this->user->getId()]);
        self::assertTrue($attempt->isPrerequisiteSatisfied());
        self::assertSame($closed, $attempt->getClosedCopy());
        self::assertSame($copy, $attempt->getInitialCopy());
        $other->close();
    }

    #[DataProvider('feedbackCases')]
    public function testFeedbackUsesRealEngineAndPerfectCopyCloses(string $copy, bool $perfect): void
    {
        [$url] = $this->start();
        $token = $this->transitionToken();
        $expected = (new DictationCorrector())->correct('L’école ouvre.', $copy)->publicFeedback();
        $this->submitTransition($url, 'evaluer', $token, 0, $copy);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.dictation-summary', number_format($expected['score'], 1, ',', ' ').' %');
        if ($perfect) {
            self::assertSelectorExists('#dictation-closed-copy');
            self::assertSelectorNotExists('[data-dictation-writing-issues-value]');
            self::assertSelectorTextContains('main', 'Bravo');
        } else {
            self::assertSame($expected['issues'], json_decode($this->client->getCrawler()->filter('[data-dictation-writing-issues-value]')->attr('data-dictation-writing-issues-value'), true, flags: JSON_THROW_ON_ERROR));
            self::assertSelectorExists('#dictation-copy');
        }
        $other = self::$database->manager();
        $attempt = $other->getRepository(DictationAttempt::class)->findOneBy(['user' => $this->user->getId()]);
        self::assertSame($expected, $attempt->getInitialResult());
        self::assertSame(1, $attempt->getRevision());
        self::assertSame($perfect, $attempt->isPrerequisiteSatisfied());
        $other->close();
    }

    /** @return iterable<string, array{string, bool}> */
    public static function feedbackCases(): iterable
    {
        yield 'perfect' => ['L’école ouvre.', true];
        yield 'punctuation only 100 percent' => ['L’école ouvre', false];
        yield 'omission after elision' => ['L’ ouvre.', false];
        yield 'addition' => ['L’école ouvre demain.', false];
        yield 'linking' => ['Lecole ouvre.', false];
        yield 'separation' => ['L’ école ouvre.', false];
        yield 'unicode and punctuation' => ["😀 L’e\u{0301}cole ouvre!", false];
    }

    public function testTransitionValidationCsrfOwnershipAndWrongPhase(): void
    {
        [$url] = $this->start();
        $token = $this->transitionToken();
        foreach (['evaluer', 'terminer'] as $action) {
            $this->submitTransition($url, $action, 'invalid', 0, 'Copie');
            self::assertResponseStatusCodeSame(403);
        }
        foreach (['', str_repeat('a ', 751)] as $invalid) {
            $this->submitTransition($url, 'evaluer', $token, 0, $invalid);
            self::assertResponseStatusCodeSame(422);
        }
        $this->submitTransition($url, 'evaluer', $token, 0, 'L’ ouvre.');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $url);
        $closeToken = $this->transitionToken();
        $this->submitTransition($url, 'terminer', $closeToken, 1, '');
        self::assertResponseStatusCodeSame(422);
        $this->submitTransition($url, 'terminer', $closeToken, 0, 'Conflit');
        self::assertResponseStatusCodeSame(409);
        $other = self::$database->manager();
        [$foreign] = DictationFactory::create($other);
        $this->client->loginUser($foreign);
        foreach (['evaluer', 'terminer'] as $action) {
            $this->submitTransition($url, $action, $token, 1, 'Interdit');
            self::assertResponseStatusCodeSame(404);
        }
        $other->close();
    }
}
