<?php

declare(strict_types=1);

namespace App\Services\Courses;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Vich\UploaderBundle\Mapping\PropertyMapping;
use Vich\UploaderBundle\Naming\NamerInterface;
use Vich\UploaderBundle\Util\Transliterator;

/**
 * @implements NamerInterface<object>
 */
final class TwigFileNamer implements NamerInterface
{
    private const TWIG_EXTENSION = '.html.twig';
    private const UNIQUE_BYTES = 12;

    public function __construct(
        private readonly Transliterator $transliterator,
    ) {
    }

    public function name(object $object, PropertyMapping $mapping): string
    {
        $file = $mapping->getFile($object);

        if (!$file instanceof UploadedFile) {
            throw new \RuntimeException('Le fichier Twig uploadé est introuvable.');
        }

        $basename = $this->getBasename($file->getClientOriginalName());
        $slug = $this->transliterator->transliterate($basename);
        $slug = trim($slug, '-');

        if ('' === $slug) {
            $slug = 'cours';
        }

        $suffix = bin2hex(random_bytes(self::UNIQUE_BYTES));
        $maxSlugLength = 255 - strlen('-'.$suffix.self::TWIG_EXTENSION);

        return sprintf(
            '%s-%s%s',
            substr($slug, 0, $maxSlugLength),
            $suffix,
            self::TWIG_EXTENSION,
        );
    }

    private function getBasename(string $originalName): string
    {
        if (str_ends_with(strtolower($originalName), self::TWIG_EXTENSION)) {
            return substr($originalName, 0, -strlen(self::TWIG_EXTENSION));
        }

        return pathinfo($originalName, PATHINFO_FILENAME);
    }
}
