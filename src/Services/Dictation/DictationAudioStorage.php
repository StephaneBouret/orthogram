<?php

declare(strict_types=1);

namespace App\Services\Dictation;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** @phpstan-type Audio array{key: string, sha256: string, size: int, durationMs: int} */
final class DictationAudioStorage
{
    public const MAX_BYTES = 25 * 1024 * 1024;
    public const MAX_DURATION_MS = 30 * 60 * 1000;

    public function __construct(
        #[Autowire('%app.dictation_storage_dir%')] private readonly string $directory,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicDirectory,
    ) {
    }

    /** @return Audio */
    public function import(UploadedFile $file): array
    {
        if (!$file->isValid() || 'mp3' !== strtolower($file->getClientOriginalExtension())) {
            throw new \DomainException('Import impossible : choisissez un fichier MP3 valide.');
        }
        $duration = $this->duration($file->getPathname());
        $root = $this->root();
        $key = bin2hex(random_bytes(16)).'.mp3';
        $path = $root.DIRECTORY_SEPARATOR.$key;
        // Exclusive creation: never overwrite a file, including an existing shared reference.
        $target = @fopen($path, 'xb');
        if (false === $target) {
            throw new \RuntimeException('Impossible de créer le fichier audio privé.');
        }
        try {
            $source = fopen($file->getPathname(), 'rb');
            if (false === $source) {
                throw new \RuntimeException('Impossible de lire le fichier importé.');
            }
            try {
                $written = stream_copy_to_stream($source, $target, self::MAX_BYTES + 1);
            } finally {
                fclose($source);
            }
            if ($written !== $file->getSize() || $written > self::MAX_BYTES || !fflush($target)) {
                throw new \RuntimeException('Écriture du fichier audio incomplète.');
            }
            fclose($target);
            $target = null;
            $hash = hash_file('sha256', $path);
            if (false === $hash) {
                throw new \RuntimeException('Vérification du fichier audio impossible.');
            }

            return ['key' => $key, 'sha256' => $hash, 'size' => $written, 'durationMs' => $duration];
        } catch (\Throwable $error) {
            if (is_resource($target)) {
                fclose($target);
            }
            unlink($path); // Only the exclusive file created by this operation.
            throw $error;
        }
    }

    /** @param Audio $audio */
    public function resolve(array $audio): string
    {
        if (1 !== preg_match('/^[a-f0-9]{32}\.mp3$/D', $audio['key'])) {
            throw new \DomainException('Référence audio invalide.');
        }
        $root = $this->root();
        $candidate = $root.DIRECTORY_SEPARATOR.$audio['key'];
        $path = realpath($candidate);
        if (false === $path || is_link($candidate) || !Path::isBasePath($root, $path) || !is_file($path) || !is_readable($path)) {
            throw new \DomainException('Fichier audio privé absent ou inaccessible.');
        }

        return $path;
    }

    /** @param Audio $audio */
    public function verify(array $audio): void
    {
        $path = $this->resolve($audio);
        if (filesize($path) !== $audio['size'] || hash_file('sha256', $path) !== $audio['sha256'] || $this->duration($path) !== $audio['durationMs']) {
            throw new \DomainException('Le fichier audio ne correspond plus à sa référence. Réimportez-le.');
        }
    }

    /** Only call for a newly imported file after the owning SQL transaction has failed.
     * @param Audio $audio
     */
    public function discardNewImport(array $audio): void
    {
        $path = $this->resolve($audio);
        if (!unlink($path)) {
            throw new \RuntimeException('Nettoyage du nouvel import impossible.');
        }
    }

    private function root(): string
    {
        $root = realpath($this->directory);
        $public = realpath($this->publicDirectory);
        if (false === $root || false === $public || Path::isBasePath($public, $root) || !is_dir($root)) {
            throw new \RuntimeException('Configurez un répertoire audio existant hors de public/.');
        }

        return $root;
    }

    private function duration(string $path): int
    {
        $size = filesize($path);
        if (false === $size || $size < 1 || $size > self::MAX_BYTES) {
            throw new \DomainException('Le MP3 doit peser entre 1 octet et 25 Mio.');
        }
        if (!in_array((new \finfo(FILEINFO_MIME_TYPE))->file($path), ['audio/mpeg', 'audio/mp3'], true)) {
            throw new \DomainException('Le contenu du fichier doit être un audio MP3.');
        }
        $analyzer = new \getID3();
        // Validate frame continuity through EOF, not only the default first 50 frames.
        // Work is bounded by MAX_BYTES; brute-force recovery stays disabled.
        $analyzer->options_audio_mp3_mp3_valid_check_frames = PHP_INT_MAX;
        $info = $analyzer->analyze($path);
        $seconds = $info['playtime_seconds'] ?? 0;
        if (!empty($info['error']) || !empty($info['warning']) || 'mp3' !== ($info['audio']['dataformat'] ?? null)
            || 'mp3' !== ($info['fileformat'] ?? null) || isset($info['video']) || !is_numeric($seconds)
            || !is_finite((float) $seconds) || $seconds <= 0 || $seconds * 1000 > self::MAX_DURATION_MS) {
            throw new \DomainException('MP3 illisible, endommagé ou dépassant 30 minutes. Exportez à nouveau le fichier.');
        }

        return (int) floor((float) $seconds * 1000);
    }
}
