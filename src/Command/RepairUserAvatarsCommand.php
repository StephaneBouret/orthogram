<?php

namespace App\Command;

use App\Repository\UserRepository;
use App\Services\AvatarService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:avatars:repair',
    description: 'Crée ou régénère les avatars utilisateur manquants.',
)]
final class RepairUserAvatarsCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AvatarService $avatarService,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les corrections sans écrire en base ni créer de fichier.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $created = 0;
        $regenerated = 0;

        foreach ($this->userRepository->findAll() as $user) {
            $avatar = $user->getAvatar();

            if (null === $avatar) {
                ++$created;
                $output->writeln(sprintf('Avatar à créer pour %s', $user->getEmail()));

                if (!$dryRun) {
                    $this->avatarService->createAndAssignAvatar($user);
                }

                continue;
            }

            if (!$this->avatarService->hasStoredImage($avatar)) {
                ++$regenerated;
                $output->writeln(sprintf('Avatar à régénérer pour %s', $user->getEmail()));

                if (!$dryRun) {
                    $this->avatarService->createDefaultAvatar($avatar, $user);
                }
            }
        }

        if (!$dryRun && ($created > 0 || $regenerated > 0)) {
            $this->em->flush();
        }

        $output->writeln(sprintf(
            '%d avatar(s) créé(s), %d avatar(s) régénéré(s).',
            $created,
            $regenerated
        ));

        return Command::SUCCESS;
    }
}
