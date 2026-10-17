<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Dictation;
use App\Entity\DictationVersion;
use App\Entity\Sections;
use App\Entity\User;
use App\Enum\UserAccountStatus;
use App\Services\Dictation\DictationEditorialService;
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

final class DictationAdminTest extends WebTestCase
{
    private static DictationTestDatabase $database;
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private DictationVersion $source;
    /** @var array{?string, ?string} */
    private array $previousUrls;

    public static function setUpBeforeClass(): void
    {
        self::$database = new DictationTestDatabase();
        try {
            self::$database->install();
        } catch (\Throwable $e) {
            self::$database->close();
            throw $e;
        }
    }

    protected function setUp(): void
    {
        $this->previousUrls = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = self::$database->kernelUrl();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(self::$database->name, $this->em->getConnection()->getDatabase());
        [$this->user, , $this->source] = DictationFactory::create($this->em);
        $this->user->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->close();
        parent::tearDown();
        [$_ENV['DATABASE_URL'], $_SERVER['DATABASE_URL']] = $this->previousUrls;
    }

    public static function tearDownAfterClass(): void
    {
        self::$database->close();
    }

    private function url(?DictationVersion $version = null): string
    {
        return '/admin/dictees/versions/'.($version ?? $this->source)->getId();
    }

    private function draft(): DictationVersion
    {
        return self::getContainer()->get(DictationEditorialService::class)->cloneVersion($this->source, $this->source->getRevision());
    }

    /** @return array<string, mixed> */
    private function values(string $url): array
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler->filter('form[name="form"]')->form()->getPhpValues();
    }

    public function testCreateIdentityDraftSaveUploadSegmentsPublishThenClone(): void
    {
        $section = (new Sections())->setName('Nouvelle section')->setSlug('new-'.bin2hex(random_bytes(6)))->setProgram($this->source->getDictation()->getSection()->getProgram());
        $this->em->persist($section);
        $this->em->flush();
        $values = $this->values('/admin/dictees');
        $values['form']['section'] = (string) $section->getId();
        $this->client->request('POST', '/admin/dictees', $values);
        self::assertResponseRedirects();
        $dictation = $this->em->getRepository(Dictation::class)->findOneBy(['section' => $section]);
        $url = '/admin/dictees/'.$dictation->getId();
        $values = $this->values($url);
        $values['form']['title'] = 'Synthétique';
        $values['form']['text'] = 'Le chat dort.';
        $this->client->request('POST', $url, $values);
        self::assertResponseRedirects();
        $version = $this->em->getRepository(DictationVersion::class)->findOneBy(['dictation' => $dictation]);
        foreach (['complete', 'writing'] as $role) {
            $crawler = $this->client->request('GET', $this->url($version));
            $upload = $crawler->filter('form[action$="/audio/'.$role.'"]')->form();
            $this->client->request('POST', $upload->getUri(), $upload->getPhpValues(), ['audio' => DictationAudioFixture::upload()]);
            self::assertResponseRedirects();
        }
        $values = $this->values($this->url($version));
        $values['form']['segments'] = [['start' => '0', 'end' => '1000']];
        $values['form']['correction'] = '<script>alert(1)</script> texte brut';
        $values['form']['save'] = '';
        $this->client->request('POST', $this->url($version), $values);
        self::assertResponseRedirects();
        $values = $this->values($this->url($version));
        self::assertSelectorNotExists('script:not([src]):contains("alert(1)")');
        unset($values['form']['save']);
        $values['form']['publish'] = '';
        $this->client->request('POST', $this->url($version), $values);
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertTrue($this->em->find(DictationVersion::class, $version->getId())->isPublished());
        $crawler = $this->client->request('GET', $this->url($version));
        $clone = $crawler->filter('form[action$="/clone"]')->form();
        $this->client->submit($clone);
        self::assertResponseRedirects();
        $copy = $this->em->getRepository(DictationVersion::class)->findOneBy(['dictation' => $dictation, 'number' => 2]);
        self::assertFalse($copy->isPublished());
        self::assertEquals($this->em->find(DictationVersion::class, $version->getId())->getWritingAudio(), $copy->getWritingAudio());
    }

    public function testPublishRejectsUnsavedInputAndPreservesForm(): void
    {
        $draft = $this->draft();
        $url = $this->url($draft);
        $values = $this->values($url);
        self::assertSelectorExists('[data-dictation-editor-unsaved-value="false"]');
        $values['form']['text'] = 'Saisie conservée.';
        $values['form']['publish'] = '';
        $this->client->request('POST', $url, $values);
        self::assertResponseStatusCodeSame(409);
        self::assertSelectorExists('[data-dictation-editor-unsaved-value="true"]');
        self::assertInputValueSame('form[revision]', $values['form']['revision']);
        self::assertSelectorTextContains('textarea[name="form[text]"]', 'Saisie conservée.');
        self::assertSame('L’école ouvre.', $this->em->getConnection()->fetchOne('SELECT canonical_text FROM dictation_version WHERE id = ?', [$draft->getId()]));
    }

    public function testStaleFormRevisionKeepsInputWithoutRetry(): void
    {
        $draft = $this->draft();
        $url = $this->url($draft);
        $values = $this->values($url);
        $revision = $values['form']['revision'];
        $other = self::$database->manager();
        $current = $other->find(DictationVersion::class, $draft->getId());
        $current->edit('Concurrent', 'Autre texte.', null);
        $other->flush();
        $values['form']['text'] = 'Ma saisie.';
        $values['form']['save'] = '';
        $this->client->request('POST', $url, $values);
        self::assertResponseStatusCodeSame(409);
        self::assertSelectorTextContains('textarea[name="form[text]"]', 'Ma saisie.');
        self::assertInputValueSame('form[revision]', $revision);
        self::assertSelectorExists('[data-dictation-editor-unsaved-value="true"]');
        foreach ($this->client->getCrawler()->filter('input[name="revision"]')->extract(['value']) as $actionRevision) {
            self::assertSame($revision, $actionRevision);
        }
        self::assertSame('Autre texte.', $other->getConnection()->fetchOne('SELECT canonical_text FROM dictation_version WHERE id = ?', [$draft->getId()]));
    }

    public function testCsrfAndGetMutationRejection(): void
    {
        $draft = $this->draft();
        $url = $this->url($draft);
        $values = $this->values($url);
        $values['form']['_token'] = 'wrong';
        $values['form']['save'] = '';
        $this->client->request('POST', $url, $values);
        self::assertResponseStatusCodeSame(422);
        foreach (['/clone', '/audio/writing'] as $suffix) {
            $this->client->request('GET', $url.$suffix);
            self::assertResponseStatusCodeSame(405);
            $this->client->request('POST', $url.$suffix, ['revision' => $draft->getRevision(), '_token' => 'wrong']);
            self::assertResponseStatusCodeSame(403);
        }
        $this->client->request('POST', $this->url(), $values);
        self::assertResponseStatusCodeSame(409);
    }

    public function testFormLoadedBeforePublicationPreservesSubmittedText(): void
    {
        $draft = $this->draft();
        $url = $this->url($draft);
        $values = $this->values($url);
        $other = self::$database->manager();
        $current = $other->find(DictationVersion::class, $draft->getId());
        (new DictationEditorialService($other, new \App\Services\Dictation\DictationCorrector(), DictationAudioFixture::storage()))->publish($current, $current->getRevision());
        $values['form']['text'] = 'Saisie après publication concurrente.';
        $values['form']['save'] = '';
        $this->client->request('POST', $url, $values);
        self::assertResponseStatusCodeSame(409);
        self::assertSelectorTextContains('textarea[name="form[text]"]', 'Saisie après publication concurrente.');
        self::assertInputValueSame('form[revision]', $values['form']['revision']);
        self::assertSelectorExists('[data-dictation-editor-unsaved-value="true"]');
        self::assertInputValueSame('revision', $values['form']['revision']);
        self::assertSame('L’école ouvre.', $other->getConnection()->fetchOne('SELECT canonical_text FROM dictation_version WHERE id = ?', [$draft->getId()]));
        self::assertTrue($current->isPublished());
    }

    /** @return iterable<string, array{string}> */
    public static function profiles(): iterable
    {
        foreach (['user', 'anonymous', 'inactive-admin', '2fa-admin'] as $profile) {
            yield $profile => [$profile];
        }
    }

    #[DataProvider('profiles')]
    public function testOtherProfilesCannotAccessAdminOrMedia(string $profile): void
    {
        if ('user' === $profile) {
            $this->user->setRoles(['ROLE_USER']);
        }
        if ('inactive-admin' === $profile) {
            $this->user->setAccountStatus(UserAccountStatus::INACTIVE);
        }
        $this->em->flush();
        $this->client->loginUser($this->user);
        if ('anonymous' === $profile) {
            $this->client->getCookieJar()->clear();
        }
        if ('2fa-admin' === $profile) {
            $session = $this->client->getSession();
            $session->set('_security_main', serialize(new TwoFactorToken(new UsernamePasswordToken($this->user, 'main', $this->user->getRoles()), null, 'main', ['email'])));
            $session->save();
        }
        foreach (['/admin/dictees', $this->url(), $this->url().'/audio/writing/listen'] as $url) {
            foreach (['GET', 'HEAD', 'POST'] as $method) {
                $this->client->request($method, $url, server: ['HTTP_RANGE' => 'bytes=0-99']);
                self::assertContains($this->client->getResponse()->getStatusCode(), [302, 403, 405]);
                self::assertNotInstanceOf(BinaryFileResponse::class, $this->client->getResponse());
            }
        }
    }

    public function testAdminAudioFullHeadRangeAndNoPublicRoute(): void
    {
        $url = $this->url().'/audio/writing/listen';
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'audio/mpeg');
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertInstanceOf(BinaryFileResponse::class, $this->client->getResponse());
        $this->client->request('HEAD', $url);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Length', (string) $this->source->getWritingAudio()['size']);
        $this->client->request('GET', $url, server: ['HTTP_RANGE' => 'bytes=10-99']);
        self::assertResponseStatusCodeSame(206);
        self::assertResponseHeaderSame('Content-Range', 'bytes 10-99/'.$this->source->getWritingAudio()['size']);
        self::assertResponseHeaderSame('Content-Length', '90');
        $this->client->request('GET', '/private/dictations/'.$this->source->getWritingAudio()['key']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDuplicateSectionIsFormError(): void
    {
        $values = $this->values('/admin/dictees');
        $values['form']['section'] = (string) $this->source->getDictation()->getSection()->getId();
        $this->client->request('POST', '/admin/dictees', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'possède déjà une dictée');
    }

    public function testValidationRedisplaysSparseCollectionsAndSaveClearsUnsavedState(): void
    {
        $draft = $this->draft();
        $url = $this->url($draft);
        $values = $this->values($url);
        $revision = $values['form']['revision'];
        $values['form']['title'] = '';
        $values['form']['variants'] = [
            0 => ['referenceIndex' => '2', 'alternatives' => 'ferme'],
            2 => ['referenceIndex' => '1', 'alternatives' => 'université'],
        ];
        $values['form']['segments'] = [0 => ['start' => '0', 'end' => '500'], 2 => ['start' => '1000', 'end' => '1500']];
        $values['form']['save'] = '';
        $crawler = $this->client->request('POST', $url, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-dictation-editor-unsaved-value="true"]');
        self::assertInputValueSame('form[revision]', $revision);
        foreach (['variants', 'segments'] as $collection) {
            self::assertSame(['0', '2'], $crawler->filter('[data-collection="'.$collection.'"] [data-row]')->extract(['data-index']));
        }
        $redisplayed = $crawler->filter('form[name="form"]')->form()->getPhpValues();
        self::assertSame($values['form']['variants'], $redisplayed['form']['variants']);
        self::assertInputValueSame('form[segments][2][start]', '1000');
        $values['form']['title'] = 'Titre corrigé';
        $values['form']['variants'][3] = ['referenceIndex' => '0', 'alternatives' => 'Une'];
        $values['form']['segments'][3] = ['start' => '1600', 'end' => '1900'];
        $this->client->request('POST', $url, $values);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorExists('[data-dictation-editor-unsaved-value="false"]');
        $this->em->clear();
        $stored = $this->em->find(DictationVersion::class, $draft->getId());
        self::assertSame([2, 1, 0], array_column($stored->getVariants(), 'referenceIndex'));
        self::assertSame([['ferme'], ['université'], ['Une']], array_column($stored->getVariants(), 'alternatives'));
        self::assertSame([0, 1000, 1600], array_column($stored->getSegments(), 'start'));
        self::assertSame([500, 1500, 1900], array_column($stored->getSegments(), 'end'));
    }

    public function testEditorialLabelsOnCreationAndEdition(): void
    {
        $this->client->request('GET', '/admin/dictees/'.$this->source->getDictation()->getId());
        self::assertSelectorTextSame('label[for="form_text"]', 'Texte de référence de la dictée');
        self::assertSelectorTextContains('#form_text_help', 'Il sert de référence pour corriger les copies.');
        $this->values($this->url($this->draft()));
        self::assertSelectorTextSame('label[for="form_text"]', 'Texte de référence de la dictée');
        self::assertSelectorTextSame('label[for="form_correction"]', 'Explications de Caroline — facultatif');
        self::assertSelectorTextContains('#form_correction_help', 'fin du parcours');
        self::assertSelectorTextContains('label[for="form_reviewed"]', 'aucune variante');
        self::assertSelectorNotExists('.dictation-editor:contains("UTF-16")');
    }

    /** @return iterable<string, array{list<array{start: string, end: string}>, int, string, string}> */
    public static function segmentErrors(): iterable
    {
        yield 'chevauchement 69 ms' => [[['start' => '0', 'end' => '128383'], ['start' => '128314', 'end' => '200000']], 1, 'start', 'segment 1 (128383 ms)'];
        yield 'chevauchement 1 ms' => [[['start' => '0', 'end' => '260316'], ['start' => '260315', 'end' => '280000']], 1, 'start', 'segment 1 (260316 ms)'];
        yield 'fin égale' => [[['start' => '100', 'end' => '100']], 0, 'end', 'strictement supérieure au début (100 ms)'];
        yield 'fin inférieure' => [[['start' => '100', 'end' => '99']], 0, 'end', 'strictement supérieure au début (100 ms)'];
        yield 'texte invalide' => [[['start' => 'abc', 'end' => '100']], 0, 'start', 'nombre entier'];
        yield 'décimal invalide' => [[['start' => '0.5', 'end' => '100']], 0, 'start', 'nombre entier'];
        yield 'borne vide' => [[['start' => '', 'end' => '100']], 0, 'start', 'Saisissez cette borne'];
        yield 'borne négative' => [[['start' => '-1', 'end' => '100']], 0, 'start', 'supérieure ou égale à 0 ms'];
        yield 'fin hors durée' => [[['start' => '0', 'end' => '400000']], 0, 'end', 'dépasse la durée de la piste'];
        yield 'début hors durée' => [[['start' => '400000', 'end' => '410000']], 0, 'start', 'dépasse la durée de la piste'];
    }

    /** @param list<array{start: string, end: string}> $segments */
    #[DataProvider('segmentErrors')]
    public function testSegmentErrorsAreLocalAndKeepSubmittedValues(array $segments, int $row, string $field, string $message): void
    {
        $draft = $this->draft();
        $editorial = self::getContainer()->get(DictationEditorialService::class);
        $editorial->importAudio($draft, $draft->getRevision(), 'writing', DictationAudioFixture::longUpload());
        $values = $this->values($this->url($draft));
        $revision = $values['form']['revision'];
        $values['form']['segments'] = $segments;
        $values['form']['save'] = '';
        $crawler = $this->client->request('POST', $this->url($draft), $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#form_segments_'.$row.'_'.$field.'_error1', $message);
        self::assertInputValueSame('form[revision]', $revision);
        self::assertSelectorExists('[data-dictation-editor-unsaved-value="true"]');
        foreach ($segments as $index => $segment) {
            foreach ($segment as $key => $value) {
                self::assertInputValueSame('form[segments]['.$index.']['.$key.']', $value);
            }
        }
        self::assertSame(array_map(static fn (int $index): string => (string) ($index + 1), array_keys($segments)), $crawler->filter('[data-segment-number]')->each(static fn ($node) => $node->text()));
        self::assertSame((int) $revision, (int) $this->em->getConnection()->fetchOne('SELECT revision FROM dictation_version WHERE id = ?', [$draft->getId()]));
        self::assertSame('[]', $this->em->getConnection()->fetchOne('SELECT segments FROM dictation_version WHERE id = ?', [$draft->getId()]));
    }

    public function testBothOverlapsReportedAndEqualityAcceptedWithoutAdjustingBounds(): void
    {
        $draft = $this->draft();
        self::getContainer()->get(DictationEditorialService::class)->importAudio($draft, $draft->getRevision(), 'writing', DictationAudioFixture::longUpload());
        self::assertGreaterThan(300000, $draft->getWritingAudio()['durationMs']);
        $values = $this->values($this->url($draft));
        $values['form']['segments'] = [
            ['start' => '0', 'end' => '128383'], ['start' => '128314', 'end' => '200000'],
            ['start' => '200000', 'end' => '260316'], ['start' => '260315', 'end' => '280000'],
        ];
        $values['form']['save'] = '';
        $this->client->request('POST', $this->url($draft), $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#form_segments_1_start_error1', 'segment 1 (128383 ms)');
        self::assertSelectorTextContains('#form_segments_3_start_error1', 'segment 3 (260316 ms)');
        $values['form']['segments'][1]['start'] = '128383';
        $values['form']['segments'][3]['start'] = '260316';
        $this->client->request('POST', $this->url($draft), $values);
        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->find(DictationVersion::class, $draft->getId());
        self::assertSame([0, 128383, 200000, 260316], array_column($stored->getSegments(), 'start'));
        self::assertSame([128383, 200000, 260316, 280000], array_column($stored->getSegments(), 'end'));
    }

    public function testCanonicalChangeInvalidatesVariantTargetsUntilReviewedOnSavedText(): void
    {
        $draft = $this->draft();
        $url = $this->url($draft);
        $values = $this->values($url);
        $values['form']['variants'] = [['referenceIndex' => '1', 'alternatives' => 'université']];
        $values['form']['save'] = '';
        $this->client->request('POST', $url, $values);
        self::assertResponseRedirects();
        $values = $this->values($url);
        self::assertSelectorTextContains('select[name="form[variants][0][referenceIndex]"]', 'école');
        $values['form']['text'] = 'Mot.';
        $values['form']['reviewed'] = '1';
        $values['form']['save'] = '';
        $this->client->request('POST', $url, $values);
        self::assertResponseRedirects();
        $values = $this->values($url);
        self::assertSelectorTextContains('.alert-warning', 'doivent être revues');
        self::assertSelectorTextContains('select[name="form[variants][0][referenceIndex]"]', 'cible à remplacer');
        $values['form']['variants'][0]['referenceIndex'] = '0';
        $values['form']['variants'][0]['alternatives'] = 'Parole';
        $values['form']['reviewed'] = '1';
        $values['form']['save'] = '';
        $this->client->request('POST', $url, $values);
        self::assertResponseRedirects();
        $values = $this->values($url);
        self::assertSelectorNotExists('.alert-warning');
        $values['form']['publish'] = '';
        $this->client->request('POST', $url, $values);
        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->find(DictationVersion::class, $draft->getId());
        self::assertTrue($stored->isPublished());
        self::assertEquals([['referenceIndex' => 0, 'alternatives' => ['Parole']]], $stored->getVariants());
    }
}
