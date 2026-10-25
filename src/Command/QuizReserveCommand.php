<?php

namespace App\Command;

use App\Services\ModuleOneReserveImporter;
use App\Services\ModuleOneReserveManifest;
use App\Services\QuizCommandDestination;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:quiz:module1-reserves', description: 'Audit/prévisualisation sans écriture ; import complémentaire explicite via --apply.')]
class QuizReserveCommand extends Command
{
    public function __construct(protected readonly ModuleOneReserveImporter $importer, protected readonly EntityManagerInterface $em, private readonly QuizCommandDestination $destination)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Exécuter sur la base vérifiée.')
            ->addOption('expect-database', null, InputOption::VALUE_REQUIRED, 'Nom exact exigé de SELECT DATABASE().')
            ->addOption('expect-plan', null, InputOption::VALUE_REQUIRED, 'Empreinte de la prévisualisation exigée pour écrire.')
            ->addOption('allow-work-database', null, InputOption::VALUE_NONE, 'Réservé à une étape ultérieure explicitement autorisée sur orthogram.')
            ->addOption('expect-env', null, InputOption::VALUE_REQUIRED, 'Environnement Symfony attendu ; prod obligatoire en production.')
            ->addOption('expect-host', null, InputOption::VALUE_REQUIRED, 'Hôte TCP MySQL exact attendu ; obligatoire en production.')
            ->addOption('expect-port', null, InputOption::VALUE_REQUIRED, 'Port TCP MySQL attendu ; obligatoire en production.')
            ->addOption('expect-server', null, InputOption::VALUE_REQUIRED, 'Valeur exacte attendue de @@hostname ; obligatoire en production.')
            ->addOption('allow-production-database', null, InputOption::VALUE_NONE, 'Autoriser explicitement une écriture en prod sur la destination vérifiée.')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Fichier JSON de rapport (en plus de la sortie standard).');
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
            $destination = $this->destination->verify($db, $input->getOptions());
            // Production plans cannot be copied between environments or destinations.
            $context = 'prod' === $destination['environment'] ? $destination : [];
            if ($input->getOption('apply')) {
                ModuleOneReserveManifest::require(is_string($input->getOption('expect-plan')) && 1 === preg_match('/^[a-f0-9]{64}$/D', $input->getOption('expect-plan')), '--expect-plan SHA-256 requis.');
                $report = $this->importer->apply($this->action($input), $input->getOption('expect-plan'), $context);
            } else {
                $db->executeStatement('SET TRANSACTION READ ONLY');
                $db->beginTransaction();
                $readonly = true;
                $report = $this->importer->plan($this->action($input), $context);
                $db->rollBack();
                $readonly = false;
            }
            $report['database'] = $destination['database'];
            $report['connection'] = $destination;
            $report['write_requested'] = (bool) $input->getOption('apply');
            $status = empty($report['errors']) ? Command::SUCCESS : Command::FAILURE;
        } catch (\Throwable $error) {
            if ($readonly && $db->isTransactionActive()) {
                $db->rollBack();
            }
            $report = ['error' => $error instanceof \DomainException ? $error->getMessage() : 'Erreur technique ; opération interrompue.', 'error_class' => get_class($error), 'applied' => false];
            $status = Command::FAILURE;
        }
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (is_string($path = $input->getOption('report'))) {
            if (false === file_put_contents($path, $json."\n")) {
                throw new \RuntimeException('Rapport non enregistrable : '.$path);
            }
        }
        $output->writeln($json, OutputInterface::OUTPUT_RAW);

        return $status;
    }
}
