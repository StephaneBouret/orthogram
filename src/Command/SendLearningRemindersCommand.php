<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\LearningReminderProcessingOutcome;
use App\Repository\LearningReminderRepository;
use App\Services\LearningReminderDispatchLock;
use App\Services\LearningReminderProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:learning-reminder:send-due',
    description: 'Traite et envoie les rappels d’apprentissage arrivés à échéance.',
)]
final class SendLearningRemindersCommand extends Command
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly LearningReminderRepository $repository,
        private readonly LearningReminderProcessor $processor,
        private readonly LearningReminderDispatchLock $dispatchLock,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selected = 0;
        $sent = 0;
        $rescheduled = 0;
        $disabled = 0;
        $failed = 0;
        $status = Command::SUCCESS;
        $acquired = false;

        try {
            $acquired = $this->dispatchLock->acquire();

            if (!$acquired) {
                $output->writeln('Une exécution des rappels d’apprentissage est déjà en cours.');
            } else {
                $dueAt = $this->clock
                    ->now()
                    ->setTimezone(new \DateTimeZone('UTC'));
                $afterNextRunAt = null;
                $afterId = null;
                $persistenceFailed = false;

                while (true) {
                    $batch = $this->repository->findDueBatch(
                        $dueAt,
                        self::BATCH_SIZE,
                        $afterNextRunAt,
                        $afterId,
                    );

                    if ([] === $batch) {
                        break;
                    }

                    $selected += \count($batch);
                    $lastReminder = $batch[array_key_last($batch)];
                    $nextCursorAt = $lastReminder->getNextRunAt();
                    $nextCursorId = $lastReminder->getId();

                    if (!$nextCursorAt instanceof \DateTimeImmutable || null === $nextCursorId) {
                        throw new \LogicException('Le dernier rappel du lot ne fournit pas un curseur valide.');
                    }

                    foreach ($batch as $reminder) {
                        try {
                            $outcome = $this->processor->process($reminder);
                        } catch (\Throwable $exception) {
                            ++$failed;
                            $this->logger->error('Échec du traitement d’un rappel d’apprentissage.', [
                                'reminderId' => $reminder->getId(),
                                'exceptionClass' => $exception::class,
                            ]);
                            $output->writeln(sprintf(
                                '<error>Rappel #%s en erreur.</error>',
                                $reminder->getId() ?? 'inconnu',
                            ));

                            continue;
                        }

                        try {
                            $this->entityManager->flush();
                        } catch (\Throwable $exception) {
                            ++$failed;
                            $status = Command::FAILURE;
                            $persistenceFailed = true;
                            $this->logger->error('Échec de persistance d’un rappel d’apprentissage.', [
                                'reminderId' => $reminder->getId(),
                                'exceptionClass' => $exception::class,
                            ]);

                            break;
                        }

                        match ($outcome) {
                            LearningReminderProcessingOutcome::SENT => ++$sent,
                            LearningReminderProcessingOutcome::RESCHEDULED => ++$rescheduled,
                            LearningReminderProcessingOutcome::DISABLED => ++$disabled,
                        };
                    }

                    if ($persistenceFailed) {
                        break;
                    }

                    $this->entityManager->clear();
                    $afterNextRunAt = $nextCursorAt;
                    $afterId = $nextCursorId;
                }

                if ($failed > 0) {
                    $status = Command::FAILURE;
                }
            }
        } catch (\Throwable $exception) {
            $status = Command::FAILURE;
            $this->logger->error('Échec d’infrastructure des rappels d’apprentissage.', [
                'exceptionClass' => $exception::class,
            ]);
        } finally {
            if ($acquired) {
                try {
                    if (!$this->dispatchLock->release()) {
                        $status = Command::FAILURE;
                        $this->logger->error('Le verrou des rappels d’apprentissage n’a pas pu être libéré.');
                    }
                } catch (\Throwable $exception) {
                    $status = Command::FAILURE;
                    $this->logger->error('Échec lors de la libération du verrou des rappels d’apprentissage.', [
                        'exceptionClass' => $exception::class,
                    ]);
                }
            }
        }

        $output->writeln(sprintf('Sélectionnés : %d', $selected));
        $output->writeln(sprintf('Envoyés : %d', $sent));
        $output->writeln(sprintf('Replanifiés : %d', $rescheduled));
        $output->writeln(sprintf('Désactivés : %d', $disabled));
        $output->writeln(sprintf('En erreur : %d', $failed));

        return $status;
    }
}
