<?php

namespace App\Command;

use App\Services\ModuleThreeQuizManifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'app:quiz:module3-renewal', description: 'Prévisualiser ou modifier séparément le renouvellement des trois quiz tracés sur base jetable ou en production.')]
final class ModuleThreeQuizRenewalCommand extends ModuleThreeQuizImportCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('mode', InputArgument::REQUIRED, 'enable ou disable.');
    }

    protected function action(InputInterface $input): string
    {
        $mode = $input->getArgument('mode');
        ModuleThreeQuizManifest::require(in_array($mode, ['enable', 'disable'], true), 'Mode attendu : enable ou disable.');

        return $mode;
    }
}
