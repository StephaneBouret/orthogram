<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'app:quiz:module1-renewal', description: 'Activation ou désactivation distincte, limitée aux quatre quiz du module 1.')]
final class QuizRenewalCommand extends QuizReserveCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('mode', InputArgument::REQUIRED, 'enable ou disable ; sans --apply : prévisualisation.');
    }

    protected function action(InputInterface $input): string
    {
        $action = $input->getArgument('mode');
        if (!in_array($action, ['enable', 'disable'], true)) {
            throw new \DomainException('Mode attendu : enable ou disable.');
        }

        return $action;
    }
}
