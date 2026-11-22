<?php

namespace App\Command;

use App\Services\ModuleThreeQuizDestination;
use App\Services\ModuleThreeQuizImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'app:quiz:module3-import', description: 'Prévisualiser ou importer les trois quiz fixes du module 3 sur la destination vérifiée.')]
class ModuleThreeQuizImportCommand extends EditorialQuizImportCommand
{
    public function __construct(ModuleThreeQuizImporter $importer, EntityManagerInterface $em, ModuleThreeQuizDestination $destination)
    {
        parent::__construct(static fn () => $importer, $em, $destination);
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('allow-work-database', null, InputOption::VALUE_NONE, 'Consentir à l’import fixe sur la base locale orthogram en dev.')
            ->addOption('allow-production-database', null, InputOption::VALUE_NONE, 'Consentir à appliquer le plan relu sur la destination de production vérifiée.');
    }
}
