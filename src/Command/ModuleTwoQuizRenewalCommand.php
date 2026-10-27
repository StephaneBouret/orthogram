<?php

namespace App\Command;

use App\Services\ModuleTwoQuizManifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'app:quiz:module2-renewal', description: 'Activation/désactivation des trois quiz tracés du module 2 ; consentement explicite sur orthogram locale.')]
final class ModuleTwoQuizRenewalCommand extends ModuleTwoQuizImportCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('mode', InputArgument::REQUIRED, 'enable ou disable.');
    }

    protected function action(InputInterface $input): string
    {
        $mode = $input->getArgument('mode');
        ModuleTwoQuizManifest::require(in_array($mode, ['enable', 'disable'], true), 'Mode attendu : enable ou disable.');

        return $mode;
    }
}
