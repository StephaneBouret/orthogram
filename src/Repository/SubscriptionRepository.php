<?php

namespace App\Repository;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SubscriptionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    public function findBlockingSubscriptionForUser(User $user): ?Subscription
    {
        $now = new \DateTimeImmutable();

        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.status = :suspended OR (s.status = :active AND (s.isLifetime = true OR ((s.startsAt IS NULL OR s.startsAt <= :now) AND (s.endsAt IS NULL OR s.endsAt >= :now))))')
            ->setParameter('user', $user)
            ->setParameter('suspended', SubscriptionStatus::SUSPENDED)
            ->setParameter('active', SubscriptionStatus::ACTIVE)
            ->setParameter('now', $now)
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestPendingForUser(User $user): ?Subscription
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', SubscriptionStatus::PENDING)
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
