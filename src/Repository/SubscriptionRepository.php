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

    public function findCurrentForUser(User $user): ?Subscription
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', SubscriptionStatus::ACTIVE)
            ->orderBy('s.endsAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Subscription[]
     */
    public function findSubscriptionsToRemindInDays(int $days): array
    {
        $now = new \DateTimeImmutable('today');
        $targetStart = $now->modify(sprintf('+%d days', $days))->setTime(0, 0);
        $targetEnd = $now->modify(sprintf('+%d days', $days))->setTime(23, 59, 59);

        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.status = :status')
            ->andWhere('s.isLifetime = false')
            ->andWhere('s.endsAt BETWEEN :start AND :end')
            ->setParameter('status', SubscriptionStatus::ACTIVE)
            ->setParameter('start', $targetStart)
            ->setParameter('end', $targetEnd)
            ->orderBy('s.endsAt', 'ASC');

        if (30 === $days) {
            $qb->andWhere('s.reminder30SentAt IS NULL');
        }

        if (15 === $days) {
            $qb->andWhere('s.reminder15SentAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Subscription[]
     */
    public function findExpiredAnnualSubscriptions(): array
    {
        $now = new \DateTimeImmutable();

        return $this->createQueryBuilder('s')
            ->andWhere('s.status = :status')
            ->andWhere('s.isLifetime = false')
            ->andWhere('s.endsAt IS NOT NULL')
            ->andWhere('s.endsAt < :now')
            ->setParameter('status', SubscriptionStatus::ACTIVE)
            ->setParameter('now', $now)
            ->orderBy('s.endsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Subscription[]
     */
    public function findActiveAnnualSubscriptionsForUserExcept(User $user, Subscription $excludedSubscription): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.status = :status')
            ->andWhere('s.isLifetime = false')
            ->setParameter('user', $user)
            ->setParameter('status', SubscriptionStatus::ACTIVE)
            ->orderBy('s.endsAt', 'DESC');

        if (null !== $excludedSubscription->getId()) {
            $qb
                ->andWhere('s.id != :excludedSubscriptionId')
                ->setParameter('excludedSubscriptionId', $excludedSubscription->getId());
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Subscription[]
     */
    public function findSupersededActiveAnnualSubscriptions(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status = :status')
            ->andWhere('s.isLifetime = false')
            ->andWhere('s.user IS NOT NULL')
            ->andWhere(
                'EXISTS (
                    SELECT newer.id
                    FROM App\Entity\Subscription newer
                    WHERE newer.user = s.user
                    AND newer.status = :status
                    AND newer.isLifetime = false
                    AND newer.id != s.id
                    AND newer.startsAt > s.startsAt
                )'
            )
            ->setParameter('status', SubscriptionStatus::ACTIVE)
            ->orderBy('s.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveLifetimeOrSuspendedForUser(User $user): ?Subscription
    {
        $subscriptions = $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();

        foreach ($subscriptions as $subscription) {
            if ($subscription->isSuspended()) {
                return $subscription;
            }

            if ($subscription->isActive() && $subscription->isLifetime()) {
                return $subscription;
            }
        }

        return null;
    }
}
