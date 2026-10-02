<?php

declare(strict_types=1);

namespace App\Services\Courses;

use Symfony\Component\Filesystem\Path;

/** Offline transfer: deliberately independent of the kernel, database and environment dump. */
final class CourseFileTransfer
{
    /**
     * @param array{files: string, audios: string, videos: string} $sources
     *
     * @return list<string>
     */
    public function run(array $sources, string $destination, bool $copy = false, bool $verifyOnly = false): array
    {
        if ($copy && $verifyOnly) {
            throw new \InvalidArgumentException('Choose either copy or verification.');
        }
        if (!Path::isAbsolute($destination)) {
            throw new \InvalidArgumentException('Destination must be absolute.');
        }
        $destination = Path::canonicalize($destination);
        $this->assertNoLinks($destination);
        $plan = [];
        $messages = [];
        foreach (['files', 'audios', 'videos'] as $kind) {
            $source = $sources[$kind];
            if (!Path::isAbsolute($source) || false === ($root = realpath($source)) || !is_dir($root)) {
                throw new \RuntimeException('Missing absolute source directory: '.$source);
            }
            // Explicit source roots may resolve an existing deployment symlink; entries may not.
            if (Path::isBasePath($root, $destination) || Path::isBasePath($destination, $root)) {
                throw new \RuntimeException('Source and destination must be disjoint.');
            }
            $targetDirectory = $destination.'/'.$kind;
            $this->assertNoLinks($targetDirectory);
            foreach (new \DirectoryIterator($root) as $entry) {
                if ($entry->isDot()) {
                    continue;
                }
                $name = $entry->getFilename();
                if ($entry->isLink() || !$entry->isFile()) {
                    throw new \RuntimeException('Unexpected directory or link: '.$entry->getPathname());
                }
                if (in_array($name, ['.htaccess', '.gitkeep'], true)) {
                    continue;
                }
                $from = $entry->getPathname();
                $to = $targetDirectory.'/'.$name;
                $this->assertNoLinks($to);
                $hash = $this->hash($from);
                if (file_exists($to)) {
                    if (!is_file($to) || $hash !== $this->hash($to)) {
                        throw new \RuntimeException('Conflict, destination differs: '.$to);
                    }
                    $messages[] = 'IDENTICAL '.$kind.'/'.$name.' SHA256 '.$hash;
                } elseif ($verifyOnly) {
                    throw new \RuntimeException('Missing copy: '.$to);
                } else {
                    $messages[] = ($copy ? 'COPY ' : 'WOULD COPY ').$kind.'/'.$name.' SHA256 '.$hash;
                    $plan[] = [$from, $to, $hash];
                }
            }
        }

        // Complete preflight before writing anything; never overwrite even during a concurrent run.
        if ($copy) {
            foreach (['files', 'audios', 'videos'] as $kind) {
                $directory = $destination.'/'.$kind;
                if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                    throw new \RuntimeException('Cannot create destination.');
                }
            }
            foreach ($plan as [$from, $to, $hash]) {
                $input = fopen($from, 'rb');
                $output = fopen($to, 'xb');
                if (false === $input || false === $output) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    throw new \RuntimeException('Cannot exclusively create copy: '.$to);
                }
                try {
                    if (false === stream_copy_to_stream($input, $output) || !fflush($output)) {
                        throw new \RuntimeException('Incomplete copy: '.$to);
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
                if ($hash !== $this->hash($to) || $hash !== $this->hash($from)) {
                    throw new \RuntimeException('Integrity failure: '.$to);
                }
            }
            // Check the entire source inventory again, including files that were already identical.
            $this->run($sources, $destination, verifyOnly: true);
        }

        $messages[] = ($verifyOnly ? 'VERIFIED' : ($copy ? 'COPIED AND VERIFIED' : 'DRY RUN')).' — '.count($messages).' files; sources retained.';

        return $messages;
    }

    private function hash(string $file): string
    {
        $hash = hash_file('sha256', $file);
        if (false === $hash) {
            throw new \RuntimeException('Cannot hash file: '.$file);
        }

        return $hash;
    }

    private function assertNoLinks(string $path): void
    {
        for ($current = $path;; $current = dirname($current)) {
            if (is_link($current)) {
                throw new \RuntimeException('Destination symlink refused: '.$current);
            }
            if (dirname($current) === $current) {
                break;
            }
        }
    }
}
