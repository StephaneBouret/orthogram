<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Entity\Courses;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Twig\Environment;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

final class CourseFileService
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly UploaderHelper $uploaderHelper,
        private readonly Filesystem $filesystem,
        private readonly Environment $twig,
    ) {}

    public function getFileContent(Courses $course): ?string
    {
        $filePath = $this->uploaderHelper->asset($course, 'partialFile');

        if ($filePath === null || $filePath === '') {
            return null;
        }

        $fullPath = Path::join($this->projectDir, 'public', ltrim($filePath, '/'));

        if (!$this->filesystem->exists($fullPath) || !is_file($fullPath) || !is_readable($fullPath)) {
            return null;
        }

        $content = file_get_contents($fullPath);

        if ($content === false) {
            return null;
        }

        return $this->twig->createTemplate($content)->render([
            'course' => $course,
            'section' => $course->getSection(),
            'program' => $course->getSection()?->getProgram(),
        ]);
    }
}
