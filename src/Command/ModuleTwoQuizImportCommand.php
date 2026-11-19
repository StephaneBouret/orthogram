<?php

namespace App\Command;

use App\Services\ModuleTwoQuizDestination;
use App\Services\ModuleTwoQuizImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'app:quiz:module2-import', description: 'Prévisualisation du module 2 ; import fixe avec consentement distinct local/production.')]
class ModuleTwoQuizImportCommand extends EditorialQuizImportCommand
{
    public function __construct(ModuleTwoQuizImporter $importer, EntityManagerInterface $em, ModuleTwoQuizDestination $destination)
    {
        parent::__construct($importer, $em, $destination);
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('allow-work-database', null, InputOption::VALUE_NONE, 'Consentir explicitement à l’opération demandée sur orthogram en dev.')
            ->addOption('allow-production-database', null, InputOption::VALUE_NONE, 'Consentir explicitement à l’opération demandée en prod, avec toutes les assertions de destination.');
    }
}
