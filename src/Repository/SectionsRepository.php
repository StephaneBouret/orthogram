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
            ->orderBy('s.position', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->addOrderBy('c.position', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByProgramAndSlug(Program $program, string $slug): ?Sections
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.courses', 'c')
            ->addSelect('c')
            ->andWhere('s.program = :program')
            ->andWhere('s.slug = :slug')
            ->setParameter('program', $program)
            ->setParameter('slug', $slug)
            ->addOrderBy('c.position', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function getNextPositionForProgram(Program $program): int
    {
        $maxPosition = $this->createQueryBuilder('s')
            ->select('MAX(s.position)')
            ->andWhere('s.program = :program')
            ->setParameter('program', $program)
            ->getQuery()
            ->getSingleScalarResult();

        return $maxPosition === null ? 0 : ((int) $maxPosition) + 1;
    }

    /**
     * @return list<Sections>
     */
    public function findOrderedByProgramExcluding(Program $program, ?Sections $excludedSection = null): array
    {
        $queryBuilder = $this->createQueryBuilder('s')
            ->andWhere('s.program = :program')
            ->setParameter('program', $program)
            ->orderBy('s.position', 'ASC')
            ->addOrderBy('s.id', 'ASC');

        if ($excludedSection?->getId() !== null) {
            $queryBuilder
                ->andWhere('s.id != :excludedSectionId')
                ->setParameter('excludedSectionId', $excludedSection->getId());
        }

        return $queryBuilder->getQuery()->getResult();
    }
}
