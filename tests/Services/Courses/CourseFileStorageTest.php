<?php

declare(strict_types=1);

namespace App\Tests\Services\Courses;

use App\Entity\Courses;
use App\Enum\CourseFileKind;
use App\Services\Courses\CourseFileStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Vich\UploaderBundle\Storage\StorageInterface;

final class CourseFileStorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/orthogram-storage-'.bin2hex(random_bytes(8));
        foreach (['private/files', 'private/audios', 'outside'] as $directory) {
            mkdir($this->root.'/'.$directory, 0700, true);
            file_put_contents($this->root.'/'.$directory.'/fixture', 'isolated fixture');
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testVichPathMustStayInsideTheSelectedCategory(): void
    {
        $course = (new Courses())->setPartialFileName('fixture');
        foreach (['outside', 'private/audios'] as $directory) {
            $vich = $this->createMock(StorageInterface::class);
            $vich->expects(self::once())->method('resolvePath')->with($course, 'partialFile')->willReturn($this->root.'/'.$directory.'/fixture');
            self::assertNull((new CourseFileStorage($vich, $this->root.'/private'))->resolve($course, CourseFileKind::Source));
        }
    }

    public function testOutgoingDirectoryLinkIsRejectedIncludingWindowsJunctions(): void
    {
        $link = $this->root.'/private/videos';
        $target = $this->root.'/outside';
        if ('Windows' === PHP_OS_FAMILY) {
            $quotedLink = "'".str_replace("'", "''", $link)."'";
            $quotedTarget = "'".str_replace("'", "''", $target)."'";
            $process = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', 'New-Item -ItemType Junction -Path '.$quotedLink.' -Target '.$quotedTarget.' -ErrorAction Stop | Out-Null']);
            self::assertSame(0, $process->run(), $process->getErrorOutput());
        } else {
            self::assertTrue(symlink($target, $link));
        }
        try {
            $vich = $this->createMock(StorageInterface::class);
            $vich->expects(self::never())->method('resolvePath');
            self::assertNull((new CourseFileStorage($vich, $this->root.'/private'))->resolve((new Courses())->setVideoName('fixture'), CourseFileKind::Video));
        } finally {
            // Remove just this fixture's link; never traverse its target during cleanup.
            if ('Windows' === PHP_OS_FAMILY) {
                rmdir($link);
            } else {
                unlink($link);
            }
        }
        self::assertFileExists($target.'/fixture');
    }
}
