<?php

declare(strict_types=1);

namespace App\Tests\Services\Courses;

use App\Services\Courses\CourseFileTransfer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class CourseFileTransferTest extends TestCase
{
    private string $root;
    /** @var array{files: string, audios: string, videos: string} */
    private array $sources;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/orthogram-transfer-'.bin2hex(random_bytes(8));
        foreach (['files', 'audios', 'videos'] as $kind) {
            $this->sources[$kind] = $this->root.'/source/'.$kind;
            mkdir($this->sources[$kind], 0700, true);
            file_put_contents($this->sources[$kind].'/fixture.bin', $kind."\0fixture");
            file_put_contents($this->sources[$kind].'/.htaccess', 'not copied');
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testDryRunCopyIntegrityAndIdempotentRerun(): void
    {
        $tool = new CourseFileTransfer();
        $target = $this->root.'/private';
        $messages = $tool->run($this->sources, $target);
        self::assertStringStartsWith('DRY RUN', end($messages));
        self::assertDirectoryDoesNotExist($target);
        $tool->run($this->sources, $target, copy: true);
        foreach ($this->sources as $kind => $source) {
            self::assertFileExists($source.'/fixture.bin');
            self::assertSame(hash_file('sha256', $source.'/fixture.bin'), hash_file('sha256', $target.'/'.$kind.'/fixture.bin'));
            self::assertFileDoesNotExist($target.'/'.$kind.'/.htaccess');
        }
        $messages = $tool->run($this->sources, $target, copy: true);
        self::assertCount(4, $messages);
        self::assertStringStartsWith('IDENTICAL', $messages[0]);
        $tool->run($this->sources, $target, verifyOnly: true);
    }

    public function testConflictFailsBeforeAnyCopyAndPreservesDestinationAndSources(): void
    {
        $target = $this->root.'/private';
        mkdir($target.'/videos', 0700, true);
        file_put_contents($target.'/videos/fixture.bin', 'different');
        try {
            (new CourseFileTransfer())->run($this->sources, $target, copy: true);
            self::fail('Conflict was not detected');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Conflict', $exception->getMessage());
        }
        self::assertFileDoesNotExist($target.'/files/fixture.bin');
        self::assertSame('different', file_get_contents($target.'/videos/fixture.bin'));
        self::assertSame("videos\0fixture", file_get_contents($this->sources['videos'].'/fixture.bin'));
    }

    public function testPartialSuccessfulTransferCanResume(): void
    {
        $target = $this->root.'/private';
        mkdir($target.'/files', 0700, true);
        copy($this->sources['files'].'/fixture.bin', $target.'/files/fixture.bin');
        $tool = new CourseFileTransfer();
        try {
            $tool->run($this->sources, $target, verifyOnly: true);
            self::fail('Missing copies were not detected');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Missing copy', $exception->getMessage());
        }
        $tool->run($this->sources, $target, copy: true);
        $messages = $tool->run($this->sources, $target, verifyOnly: true);
        self::assertStringStartsWith('VERIFIED', end($messages));
    }

    public function testOverlappingDestinationIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        (new CourseFileTransfer())->run($this->sources, $this->sources['files'].'/nested', copy: true);
    }

    public function testUnexpectedNestedSourceStopsTransfer(): void
    {
        mkdir($this->sources['videos'].'/unexpected');
        $this->expectException(\RuntimeException::class);
        (new CourseFileTransfer())->run($this->sources, $this->root.'/private', copy: true);
    }

    public function testCommandDefaultsToSimulationAndVerificationReturnsFailureUntilCopied(): void
    {
        $args = [PHP_BINARY, dirname(__DIR__, 3).'/bin/transfer-course-files.php'];
        foreach ($this->sources as $kind => $path) {
            $args[] = '--source-'.$kind.'='.$path;
        }
        $args[] = '--destination='.$this->root.'/private';
        $dry = new Process($args);
        self::assertSame(0, $dry->run());
        self::assertStringContainsString('DRY RUN', $dry->getOutput());
        self::assertDirectoryDoesNotExist($this->root.'/private');
        self::assertSame(1, (new Process([...$args, '--verify-only']))->run());
        self::assertSame(0, (new Process([...$args, '--copy']))->run());
        self::assertSame(0, (new Process([...$args, '--verify-only']))->run());
        self::assertSame(0, (new Process([...$args, '--copy']))->run());
    }
}
