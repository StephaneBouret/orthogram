<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\DictationVersion;
use App\Services\Dictation\DictationCorrector;
use App\Services\Dictation\DictationEditorialService;
use App\Tests\Support\DictationAudioFixture;
use App\Tests\Support\DictationFactory;
use App\Tests\Support\DictationTestDatabase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DictationEditorialMediaPersistenceTest extends TestCase
{
    private static DictationTestDatabase $database;
    private EntityManagerInterface $em;

    public static function setUpBeforeClass(): void
    {
        self::$database = new DictationTestDatabase();
        self::$database->install();
    }

    protected function setUp(): void
    {
        $this->em = self::$database->manager();
    }

    public static function tearDownAfterClass(): void
    {
        self::$database->close();
    }

    private function service(?EntityManagerInterface $em = null): DictationEditorialService
    {
        return new DictationEditorialService($em ?? $this->em, new DictationCorrector(), DictationAudioFixture::storage());
    }

    public function testCloneReusesImmutableMediaAndPreservesOldAttemptAndResults(): void
    {
        [$user, $dictation, $source] = DictationFactory::create($this->em);
        $attempts = DictationFactory::service($this->em);
        $state = $attempts->start($user, $dictation->getId());
        $attempts->transition($user, $state['attemptId'], \App\Enum\DictationAction::Evaluate, 0, 'L’ ouvre.');
        $before = $this->em->getConnection()->fetchAssociative('SELECT * FROM dictation_attempt WHERE id = ?', [$state['attemptId']]);
        $oldAudio = $source->getWritingAudio();
        $copy = $this->service()->cloneVersion($source, $source->getRevision());
        self::assertSame($source->getNumber() + 1, $copy->getNumber());
        self::assertFalse($copy->isPublished());
        self::assertEquals($oldAudio, $copy->getWritingAudio());
        self::assertEquals($source->getSegments(), $copy->getSegments());
        $revision = $copy->getRevision();
        $this->service()->importAudio($copy, $revision, 'writing', DictationAudioFixture::upload());
        self::assertSame($revision + 1, $copy->getRevision());
        self::assertSame([], $copy->getSegments());
        $copy->editSegments([['start' => 100, 'end' => 900]]);
        $this->service()->saveDraft($copy, $copy->getRevision());
        $this->service()->publish($copy, $copy->getRevision());
        $this->em->clear();
        $old = $this->em->find(DictationVersion::class, $source->getId());
        self::assertEquals($oldAudio, $old->getWritingAudio());
        DictationAudioFixture::storage()->verify($oldAudio);
        self::assertSame($before, $this->em->getConnection()->fetchAssociative('SELECT * FROM dictation_attempt WHERE id = ?', [$state['attemptId']]));
    }

    public function testFailedSqlOperationRemovesOnlyNewUpload(): void
    {
        [, , $source] = DictationFactory::create($this->em);
        $copy = $this->service()->cloneVersion($source, $source->getRevision());
        $before = glob($_SERVER['DICTATION_STORAGE_DIR'].'/*.mp3');
        try {
            $this->service()->importAudio($copy, $copy->getRevision() + 1, 'writing', DictationAudioFixture::upload());
            self::fail('Révision incorrecte acceptée.');
        } catch (ConflictHttpException) {
            self::assertSame($before, glob($_SERVER['DICTATION_STORAGE_DIR'].'/*.mp3'));
            DictationAudioFixture::storage()->verify($source->getWritingAudio());
        }
        $stored = self::$database->manager()->find(DictationVersion::class, $copy->getId());
        self::assertEquals($source->getWritingAudio(), $stored->getWritingAudio());
    }

    public function testConcurrentMediaReplacementBlocksStalePublication(): void
    {
        [, , $source] = DictationFactory::create($this->em);
        $stale = $this->service()->cloneVersion($source, $source->getRevision());
        $other = self::$database->manager();
        $current = $other->find(DictationVersion::class, $stale->getId());
        $this->service($other)->importAudio($current, $current->getRevision(), 'complete', DictationAudioFixture::upload());
        try {
            $this->service()->publish($stale, $stale->getRevision());
            self::fail('Publication périmée acceptée.');
        } catch (ConflictHttpException) {
            self::assertFalse($stale->isPublished());
        }
        $stored = self::$database->manager()->find(DictationVersion::class, $stale->getId());
        self::assertFalse($stored->isPublished());
        self::assertEquals($current->getCompleteAudio(), $stored->getCompleteAudio());
    }

    public function testConcurrentPublicationBlocksMediaReplacementAndCleansNewFile(): void
    {
        [, , $source] = DictationFactory::create($this->em);
        $stale = $this->service()->cloneVersion($source, $source->getRevision());
        $other = self::$database->manager();
        $current = $other->find(DictationVersion::class, $stale->getId());
        $this->service($other)->publish($current, $current->getRevision());
        $before = glob($_SERVER['DICTATION_STORAGE_DIR'].'/*.mp3');
        try {
            $this->service()->importAudio($stale, $stale->getRevision(), 'writing', DictationAudioFixture::upload());
            self::fail('Remplacement périmé accepté.');
        } catch (ConflictHttpException) {
            self::assertSame($before, glob($_SERVER['DICTATION_STORAGE_DIR'].'/*.mp3'));
        }
        $stored = self::$database->manager()->find(DictationVersion::class, $stale->getId());
        self::assertTrue($stored->isPublished());
        self::assertEquals($source->getWritingAudio(), $stored->getWritingAudio());
    }

    public function testUnsavedSegmentsAndAudioCannotBePublished(): void
    {
        [, , $source] = DictationFactory::create($this->em);
        $copy = $this->service()->cloneVersion($source, $source->getRevision());
        $copy->editSegments([['start' => 100, 'end' => 800]]);
        try {
            $this->service()->publish($copy, $copy->getRevision());
            self::fail('Segments non enregistrés publiés.');
        } catch (ConflictHttpException) {
            self::assertSame([['start' => 100, 'end' => 800]], $copy->getSegments());
        }
        $this->service()->saveDraft($copy, $copy->getRevision());
        $copy->replaceAudio('complete', DictationAudioFixture::storage()->import(DictationAudioFixture::upload()));
        $this->expectException(ConflictHttpException::class);
        $this->service()->publish($copy, $copy->getRevision());
    }

    public function testOrmAlsoProtectsPublishedMedia(): void
    {
        [, , $version] = DictationFactory::create($this->em);
        (new \ReflectionProperty(DictationVersion::class, 'segments'))->setValue($version, []);
        $this->expectException(\DomainException::class);
        $this->em->flush();
    }
}
