<?php

namespace App\Services;

use Doctrine\ORM\EntityManagerInterface;

final class ModuleThreeQuizImporter extends ManifestQuizImporter
{
    public function __construct(EntityManagerInterface $em, ModuleThreeQuizManifest $manifest, QuizConfigurationValidator $validator,
        QuizEditorialService $editorial, ModuleThreeQuizDestination $destination)
    {
        parent::__construct($em, $manifest, $validator, $editorial, $destination);
    }

    protected function lockPrefix(): string
    {
        return 'quiz-m3-';
    }
}
