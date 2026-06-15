<?php

namespace App\Repository;

use App\Entity\Exercice;
use App\Entity\ExerciceAttempt;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExerciceAttempt>
 */
class ExerciceAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExerciceAttempt::class);
    }

    public function findLatestByUserAndExercice(User $user, Exercice $exercice): ?ExerciceAttempt
    {
        return $this->createQueryBuilder('attempt')
            ->andWhere('attempt.user = :user')
            ->andWhere('attempt.exercice = :exercice')
            ->setParameter('user', $user)
            ->setParameter('exercice', $exercice)
            ->orderBy('attempt.submittedAt', 'DESC')
            ->addOrderBy('attempt.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countByUserAndExercice(User $user, Exercice $exercice): int
    {
        return (int) $this->createQueryBuilder('attempt')
            ->select('COUNT(attempt.id)')
            ->andWhere('attempt.user = :user')
            ->andWhere('attempt.exercice = :exercice')
            ->setParameter('user', $user)
            ->setParameter('exercice', $exercice)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
