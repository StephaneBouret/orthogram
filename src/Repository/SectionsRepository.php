<?php

namespace App\Repository;

use App\Entity\Program;
use App\Entity\Sections;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Sections>
 */
class SectionsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sections::class);
    }

    /**
     * @return Sections[]
     */
    public function findByProgramWithCourses(Program $program): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.courses', 'c')
            ->addSelect('c')
            ->andWhere('s.program = :program')
            ->setParameter('program', $program)
            ->orderBy('s.id', 'ASC')
            ->addOrderBy('c.position', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
