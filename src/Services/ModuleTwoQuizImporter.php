<?php

namespace App\Services;

use Doctrine\ORM\EntityManagerInterface;

final class ModuleTwoQuizImporter extends EditorialQuizImporter
{
    public function __construct(EntityManagerInterface $em, ModuleTwoQuizManifest $manifest, QuizConfigurationValidator $validator,
        QuizEditorialService $editorial, ModuleTwoQuizDestination $destination)
    {
        parent::__construct($em, $manifest, $validator, $editorial, $destination);
    }

    protected function lockPrefix(): string
    {
        return 'quiz-m2-';
    }
}
