<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Entity\Courses;
use App\Enum\CourseFileKind;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;
use Vich\UploaderBundle\Storage\StorageInterface;

/** Shared by server-side rendering, media playback and administrative downloads. */
final class CourseFileStorage
{
    public function __construct(
        private readonly StorageInterface $storage,
        #[Autowire('%app.course_storage_dir%')]
        private readonly string $root,
    ) {
    }

    public function resolve(Courses $course, CourseFileKind $kind): ?string
    {
        $name = $kind->filename($course);
        // Persisted names are flat, including on Windows. Reject stream wrappers and ADS too.
        if (null === $name || '' === $name || '.' === $name || '..' === $name
            || preg_match('~[\\\\/:\x00-\x1f\x7f]~', $name)) {
            return null;
        }

        $root = realpath($this->root);
        $directory = realpath($this->root.'/'.$kind->directory());
        if (false === $root || false === $directory || !Path::isBasePath($root, $directory)) {
            return null;
        }

        $path = $this->storage->resolvePath($course, $kind->field());
        $resolved = null === $path ? false : realpath($path);
        if (false === $resolved || !Path::isBasePath($directory, $resolved)
            || !is_file($resolved) || !is_readable($resolved)) {
            return null;
        }

        return $resolved;
    }
}
