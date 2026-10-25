<?php

namespace App\Command;

use App\Services\ModuleOneReserveImporter;
use App\Services\ModuleOneReserveManifest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:quiz:module1-reserves', description: 'Audit/prévisualisation sans écriture ; import complémentaire explicite via --apply.')]
class QuizReserveCommand extends Command
{
    public function __construct(protected readonly ModuleOneReserveImporter $importer, protected readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Exécuter sur la base vérifiée.')
            ->addOption('expect-database', null, InputOption::VALUE_REQUIRED, 'Nom exact exigé de SELECT DATABASE().')
            ->addOption('expect-plan', null, InputOption::VALUE_REQUIRED, 'Empreinte de la prévisualisation exigée pour écrire.')
            ->addOption('allow-work-database', null, InputOption::VALUE_NONE, 'Réservé à une étape ultérieure explicitement autorisée sur orthogram.')
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
            $params = $db->getParams();
            ModuleOneReserveManifest::require(in_array($params['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true), 'MySQL local uniquement.');
            $name = $db->fetchOne('SELECT DATABASE()');
            ModuleOneReserveManifest::require(is_string($input->getOption('expect-database')) && $name === $input->getOption('expect-database'), 'Base effective différente de --expect-database.');
            if ($input->getOption('apply')) {
                ModuleOneReserveManifest::require(('orthogram' === $name && $input->getOption('allow-work-database'))
                    || 1 === preg_match('/^orthogram_(quiz_reserve_lot3_[a-f0-9]{12}|dictation_2a_[a-f0-9]{16})(_test)?$/D', $name), 'Écriture réservée à une copie identifiée ; orthogram exige une autorisation ultérieure explicite.');
                ModuleOneReserveManifest::require(is_string($input->getOption('expect-plan')) && 64 === strlen($input->getOption('expect-plan')), '--expect-plan requis.');
                $report = $this->importer->apply($this->action($input), $input->getOption('expect-plan'));
            } else {
                $db->executeStatement('SET TRANSACTION READ ONLY');
                $db->beginTransaction();
                $readonly = true;
                $report = $this->importer->plan($this->action($input));
                $db->rollBack();
                $readonly = false;
            }
            $report['database'] = $name;
            $report['write_requested'] = (bool) $input->getOption('apply');
            $status = empty($report['errors']) ? Command::SUCCESS : Command::FAILURE;
        } catch (\Throwable $error) {
            if ($readonly && $db->isTransactionActive()) {
                $db->rollBack();
            }
            $report = ['error' => $error->getMessage(), 'applied' => false];
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
