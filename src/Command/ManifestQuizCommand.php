<?php

namespace App\Command;

use App\Services\EditorialQuizManifest;
use App\Services\LocalQuizManifest;
use App\Services\ManifestQuizDestination;
use App\Services\ManifestQuizImporter;
use App\Services\QuizConfigurationValidator;
use App\Services\QuizEditorialService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:quiz:manifest', description: 'Prévisualiser un manifeste éditorial local ou appliquer son plan relu.')]
final class ManifestQuizCommand extends EditorialQuizImportCommand
{
    public function __construct(EntityManagerInterface $em, QuizConfigurationValidator $validator, QuizEditorialService $editorial,
        ManifestQuizDestination $destination, #[Autowire('%kernel.project_dir%/public')] string $publicDirectory)
    {
        parent::__construct(static function (InputInterface $input) use ($em, $validator, $editorial, $destination, $publicDirectory): ManifestQuizImporter {
            $manifest = new LocalQuizManifest($input->getArgument('manifest'), $publicDirectory);

            return new ManifestQuizImporter($em, $manifest, $validator, $editorial, $destination);
        }, $em, $destination);
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('manifest', InputArgument::REQUIRED, 'Chemin du manifeste JSON hors de public/.')
            ->addArgument('action', InputArgument::OPTIONAL, 'import, enable ou disable.', 'import')
            ->addOption('allow-work-database', null, InputOption::VALUE_NONE, 'Consentir à l’import fixe sur la base de travail vérifiée.')
            ->addOption('allow-production-database', null, InputOption::VALUE_NONE, 'Consentir à appliquer le plan sur la production vérifiée.');
    }

    protected function action(InputInterface $input): string
    {
        $action = $input->getArgument('action');
        EditorialQuizManifest::require(in_array($action, ['import', 'enable', 'disable'], true), 'Action attendue : import, enable ou disable.');

        return $action;
    }
}
