<?php

declare(strict_types=1);

namespace App\Tests\Services\Dictation;

use App\Entity\Dictation;
use App\Entity\DictationVersion;
use App\Entity\Sections;
use App\Services\Dictation\DictationAudioStorage;
use App\Services\Dictation\DictationCorrector;
use App\Tests\Support\DictationAudioFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DictationEditorialMediaTest extends TestCase
{
    private function draft(): DictationVersion
    {
        $version = new DictationVersion(new Dictation(new Sections()), 1, 'Synthétique', 'Le chat dort.');
        DictationAudioFixture::prepare($version);

        return $version;
    }

    public function testRealSyntheticMp3HasServerDurationAndUniqueImmutableName(): void
    {
        $storage = DictationAudioFixture::storage();
        $a = $storage->import(DictationAudioFixture::upload());
        $b = $storage->import(DictationAudioFixture::upload());
        self::assertNotSame($a['key'], $b['key']);
        self::assertSame($a['sha256'], $b['sha256']);
        self::assertGreaterThanOrEqual(2000, $a['durationMs']);
        self::assertLessThan(2100, $a['durationMs']);
        $storage->verify($a);
        self::assertSame(file_get_contents(DictationAudioFixture::upload()->getPathname()), file_get_contents($storage->resolve($a)));
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidSegments(): iterable
    {
        yield 'négatif' => [[['start' => -1, 'end' => 100]]];
        yield 'vide' => [[['start' => 100, 'end' => 100]]];
        yield 'inverse' => [[['start' => 200, 'end' => 100]]];
        yield 'hors durée' => [[['start' => 0, 'end' => 3000]]];
        yield 'chevauchement' => [[['start' => 0, 'end' => 500], ['start' => 499, 'end' => 1000]]];
        yield 'ordre inverse' => [[['start' => 500, 'end' => 800], ['start' => 0, 'end' => 300]]];
        yield 'décimal' => [[['start' => 0.5, 'end' => 100]]];
        yield 'chaîne' => [[['start' => '0', 'end' => 100]]];
        yield 'structure' => [[['start' => 0]]];
        yield 'trop nombreux' => [array_fill(0, 201, ['start' => 0, 'end' => 1])];
    }

    /** @param array<mixed> $segments */
    #[DataProvider('invalidSegments')]
    public function testInvalidSegmentsAreRejected(array $segments): void
    {
        $version = $this->draft();
        $this->expectException(\DomainException::class);
        $version->editSegments($segments);
    }

    public function testGapsAllowedAndWritingReplacementInvalidatesSegments(): void
    {
        $version = $this->draft();
        $segments = [['start' => 0, 'end' => 300], ['start' => 500, 'end' => 1000]];
        $version->editSegments($segments);
        $version->replaceAudio('complete', DictationAudioFixture::storage()->import(DictationAudioFixture::upload()));
        self::assertSame($segments, $version->getSegments());
        $version->replaceAudio('writing', DictationAudioFixture::storage()->import(DictationAudioFixture::upload()));
        self::assertSame([], $version->getSegments());
        $this->expectException(\DomainException::class);
        $version->publish(new DictationCorrector(), DictationAudioFixture::storage());
    }

    public function testCanonicalChangeRequiresExplicitVariantReview(): void
    {
        $version = $this->draft();
        $variants = [['referenceIndex' => 1, 'alternatives' => ['chien']]];
        $version->edit('Test', 'Le chat dort.', null, $variants);
        self::assertTrue($version->areVariantsReviewed());
        $version->edit('Test', 'Ici le chat dort.', null, $variants);
        self::assertFalse($version->areVariantsReviewed());
        try {
            $version->publish(new DictationCorrector(), DictationAudioFixture::storage());
            self::fail('Index réaffecté silencieusement.');
        } catch (\DomainException) {
            self::assertFalse($version->isPublished());
        }
        $version->edit('Test', 'Ici le chat dort.', null, [['referenceIndex' => 2, 'alternatives' => ['chien']]], true);
        $version->publish(new DictationCorrector(), DictationAudioFixture::storage());
        self::assertTrue($version->isPublished());
    }

    /** @return iterable<string, array{string}> */
    public static function publicationFailures(): iterable
    {
        foreach (['missing-track', 'missing-file', 'altered-file', 'no-segment', 'wrong-track'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('publicationFailures')]
    public function testBusinessPublicationRequiresCompleteValidMedia(string $case): void
    {
        $version = $this->draft();
        if ('missing-track' === $case) {
            (new \ReflectionProperty(DictationVersion::class, 'completeAudio'))->setValue($version, null);
        } elseif ('missing-file' === $case) {
            unlink(DictationAudioFixture::storage()->resolve($version->getWritingAudio()));
        } elseif ('altered-file' === $case) {
            file_put_contents(DictationAudioFixture::storage()->resolve($version->getWritingAudio()), 'fake');
        } elseif ('no-segment' === $case) {
            $version->editSegments([]);
        } else {
            (new \ReflectionProperty(DictationVersion::class, 'segmentsAudioKey'))->setValue($version, 'another.mp3');
        }
        $this->expectException(\DomainException::class);
        $version->publish(new DictationCorrector(), DictationAudioFixture::storage());
    }

    /** @return iterable<string, array{string}> */
    public static function frozenMutations(): iterable
    {
        foreach (['text', 'complete', 'writing', 'segments', 'variants'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('frozenMutations')]
    public function testPublishedVersionIsImmutable(string $case): void
    {
        $version = $this->draft();
        $version->publish(new DictationCorrector(), DictationAudioFixture::storage());
        $this->expectException(\DomainException::class);
        match ($case) {
            'text' => $version->edit('Autre', 'Autre.', null),
            'segments' => $version->editSegments([]),
            'variants' => $version->invalidateVariants(),
            default => $version->replaceAudio($case, $version->getWritingAudio()),
        };
    }

    /** @return iterable<string, array{string}> */
    public static function badUploads(): iterable
    {
        foreach (['fake', 'empty', 'large', 'extension', 'upload-error', 'truncated', 'corrupt-tail'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('badUploads')]
    public function testBadUploadsLeaveNoStoredFile(string $case): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dictation-upload-');
        $bytes = file_get_contents(DictationAudioFixture::upload()->getPathname());
        file_put_contents($path, match ($case) {
            'fake' => '<?php echo "attack";', 'empty' => '',
            'large' => str_repeat('x', DictationAudioStorage::MAX_BYTES + 1),
            'corrupt-tail' => $bytes.str_repeat('garbage', 1000),
            'truncated' => substr($bytes, 0, 100), default => $bytes,
        });
        $before = glob($_SERVER['DICTATION_STORAGE_DIR'].'/*.mp3');
        try {
            DictationAudioFixture::storage()->import(new UploadedFile($path, 'file.'.('extension' === $case ? 'php' : 'mp3'), 'audio/mpeg', 'upload-error' === $case ? UPLOAD_ERR_PARTIAL : null, true));
            self::fail('Import invalide accepté.');
        } catch (\DomainException) {
            self::assertSame($before, glob($_SERVER['DICTATION_STORAGE_DIR'].'/*.mp3'));
        } finally {
            unlink($path);
        }
    }

    public function testUnavailableStorageAndPublicRootAreRefused(): void
    {
        foreach ([__DIR__.'/missing', dirname(__DIR__, 3).'/public'] as $root) {
            try {
                (new DictationAudioStorage($root, dirname(__DIR__, 3).'/public'))->import(DictationAudioFixture::upload());
                self::fail('Racine invalide acceptée.');
            } catch (\RuntimeException) {
                self::assertDirectoryDoesNotExist(__DIR__.'/missing');
            }
        }
    }

    public function testTraversalReferenceIsRefused(): void
    {
        $audio = DictationAudioFixture::storage()->import(DictationAudioFixture::upload());
        $audio['key'] = '../tone.mp3';
        $this->expectException(\DomainException::class);
        DictationAudioFixture::storage()->resolve($audio);
    }
}
