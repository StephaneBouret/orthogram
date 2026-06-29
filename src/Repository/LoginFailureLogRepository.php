<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LoginFailureLog;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LoginFailureLog>
 */
class LoginFailureLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoginFailureLog::class);
    }

    public function countRecentFailuresForUser(User $user, DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('loginFailureLog')
            ->select('COUNT(loginFailureLog.id)')
            ->andWhere('loginFailureLog.user = :user')
            ->andWhere('loginFailureLog.occurredAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }
}
