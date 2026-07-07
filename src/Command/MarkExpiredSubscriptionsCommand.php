<?php

namespace App\Command;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:subscription:mark-expired',
    description: 'Passe les abonnements annuels dépassés au statut expiré.',
)]
final class MarkExpiredSubscriptionsCommand extends Command
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $subscriptions = $this->deduplicateSubscriptions([
            ...$this->subscriptionRepository->findExpiredAnnualSubscriptions(),
            ...$this->subscriptionRepository->findSupersededActiveAnnualSubscriptions(),
        ]);

        if ([] === $subscriptions) {
            $output->writeln('Aucun abonnement à expirer.');

            return Command::SUCCESS;
        }

        foreach ($subscriptions as $subscription) {
            $subscription->markExpired();

            $output->writeln(sprintf(
                'Abonnement #%d expiré pour %s',
                $subscription->getId(),
                $subscription->getEmail()
            ));
        }

        $this->em->flush();

        $output->writeln(sprintf(
            '%d abonnement(s) passé(s) en EXPIRED',
            count($subscriptions)
        ));

        return Command::SUCCESS;
    }

    /**
     * @param Subscription[] $subscriptions
     *
     * @return Subscription[]
     */
    private function deduplicateSubscriptions(array $subscriptions): array
    {
        $deduplicatedSubscriptions = [];

        foreach ($subscriptions as $subscription) {
            $deduplicatedSubscriptions[$subscription->getId()] = $subscription;
        }

        return array_values($deduplicatedSubscriptions);
    }
}
