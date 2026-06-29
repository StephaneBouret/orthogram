<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LoginFailureAlert;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LoginFailureAlert>
 */
class LoginFailureAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoginFailureAlert::class);
    }

    public function hasRecentAlertForUser(User $user, DateTimeImmutable $since): bool
    {
        return null !== $this->createQueryBuilder('loginFailureAlert')
            ->select('loginFailureAlert.id')
            ->andWhere('loginFailureAlert.user = :user')
            ->andWhere('loginFailureAlert.sentAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
}
