<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningReminder;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LearningReminder>
 */
final class LearningReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningReminder::class);
    }

    public function findOneByUser(User $user): ?LearningReminder
    {
        return $this->findOneBy([
            'user' => $user,
        ]);
    }

    /**
     * @return list<LearningReminder>
     */
    public function findDueBatch(
        \DateTimeImmutable $dueAt,
        int $limit = 100,
        ?\DateTimeImmutable $afterNextRunAt = null,
        ?int $afterId = null,
    ): array {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('La limite doit être strictement positive.');
        }

        if ((null === $afterNextRunAt) !== (null === $afterId)) {
            throw new \InvalidArgumentException('Les deux éléments du curseur doivent être fournis ensemble.');
        }

        $queryBuilder = $this->createQueryBuilder('reminder')
            ->addSelect('user')
            ->innerJoin('reminder.user', 'user')
            ->andWhere('reminder.enabled = :enabled')
            ->andWhere('reminder.nextRunAt IS NOT NULL')
            ->andWhere('reminder.nextRunAt <= :dueAt')
            ->setParameter('enabled', true)
            ->setParameter('dueAt', $dueAt->setTimezone(new \DateTimeZone('UTC')))
            ->orderBy('reminder.nextRunAt', 'ASC')
            ->addOrderBy('reminder.id', 'ASC')
            ->setMaxResults($limit);

        if (null !== $afterNextRunAt && null !== $afterId) {
            $queryBuilder
                ->andWhere(
                    '(reminder.nextRunAt > :afterNextRunAt
                    OR (reminder.nextRunAt = :afterNextRunAt AND reminder.id > :afterId))'
                )
                ->setParameter('afterNextRunAt', $afterNextRunAt->setTimezone(new \DateTimeZone('UTC')))
                ->setParameter('afterId', $afterId);
        }

        /** @var list<LearningReminder> $reminders */
        $reminders = $queryBuilder->getQuery()->getResult();

        return $reminders;
    }
}
