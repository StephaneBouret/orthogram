<?php

namespace App\Command;

use App\Services\ModuleTwoQuizDestination;
use App\Services\ModuleTwoQuizImporter;
use App\Services\ModuleTwoQuizManifest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:quiz:module2-import', description: 'Prévisualisation du module 2 ; import fixe local avec consentement explicite.')]
class ModuleTwoQuizImportCommand extends Command
{
    public function __construct(private readonly ModuleTwoQuizImporter $importer, private readonly EntityManagerInterface $em, private readonly ModuleTwoQuizDestination $destination)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Appliquer sur la destination locale vérifiée.')
            ->addOption('allow-work-database', null, InputOption::VALUE_NONE, 'Consentir explicitement à l’opération demandée sur orthogram en dev.')
            ->addOption('expect-plan', null, InputOption::VALUE_REQUIRED, 'SHA-256 du plan relu.')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Enregistrer le rapport JSON.');
        foreach (['database', 'env', 'host', 'port', 'server'] as $field) {
            $this->addOption('expect-'.$field, null, InputOption::VALUE_REQUIRED, 'Destination attendue : '.$field.'.');
        }
    }

    protected function action(InputInterface $input): string
    {
        return 'import';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->em->getConnection();
        $readonly = false;
        try {
            $action = $this->action($input);
            $context = $this->destination->verify($db, $input->getOptions(), $action);
            if ($input->getOption('apply')) {
                $hash = $input->getOption('expect-plan');
                ModuleTwoQuizManifest::require(is_string($hash) && 1 === preg_match('/^[a-f0-9]{64}$/D', $hash), '--expect-plan SHA-256 requis.');
                $report = $this->importer->apply($action, $hash, $context, (bool) $input->getOption('allow-work-database'));
            } else {
                $db->executeStatement('SET TRANSACTION READ ONLY');
                $db->beginTransaction();
                $readonly = true;
                $report = $this->importer->plan($action, $context);
                $db->rollBack();
                $readonly = false;
            }
            $report['destination'] = $context;
            $status = empty($report['errors']) ? Command::SUCCESS : Command::FAILURE;
        } catch (\Throwable $error) {
            if ($readonly && $db->isTransactionActive()) {
                $db->rollBack();
            }
            $report = ['applied' => false, 'error' => $error instanceof \DomainException ? $error->getMessage() : 'Erreur technique ; opération interrompue.', 'error_class' => get_class($error)];
            $status = Command::FAILURE;
        }
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (is_string($path = $input->getOption('report'))) {
            if (false === file_put_contents($path, $json."\n")) {
                throw new \RuntimeException('Rapport non enregistrable.');
            }
        }
        $output->writeln($json, OutputInterface::OUTPUT_RAW);

        return $status;
    }
}
