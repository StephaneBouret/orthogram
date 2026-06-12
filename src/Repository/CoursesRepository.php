<?php

namespace App\Repository;

use App\Entity\Courses;
use App\Entity\Program;
use App\Entity\Sections;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Courses>
 */
class CoursesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Courses::class);
    }

    public function countNumberCoursesBySection(Sections $section): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.section = :section')
            ->setParameter('section', $section)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getNextPositionForSection(Sections $section): int
    {
        $maxPosition = $this->createQueryBuilder('c')
            ->select('MAX(c.position)')
            ->andWhere('c.section = :section')
            ->setParameter('section', $section)
            ->getQuery()
            ->getSingleScalarResult();

        return $maxPosition === null ? 0 : ((int) $maxPosition) + 1;
    }

    /**
     * @return list<Courses>
     */
    public function findOrderedBySectionExcluding(Sections $section, ?Courses $excludedCourse = null): array
    {
        $queryBuilder = $this->createQueryBuilder('c')
            ->andWhere('c.section = :section')
            ->setParameter('section', $section)
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.id', 'ASC');

        if ($excludedCourse?->getId() !== null) {
            $queryBuilder
                ->andWhere('c.id != :excludedCourseId')
                ->setParameter('excludedCourseId', $excludedCourse->getId());
        }

        return $queryBuilder->getQuery()->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByProgram(Program $program): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->join('c.section', 's')
            ->andWhere('s.program = :program')
            ->setParameter('program', $program)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<int, int>
     */
    public function countCoursesBySections(?Program $program = null): array
    {
        $queryBuilder = $this->createQueryBuilder('c')
            ->select('s.id AS section_id, COUNT(c.id) AS course_count')
            ->join('c.section', 's')
            ->groupBy('s.id')
            ->orderBy('s.id', 'ASC');

        if ($program !== null) {
            $queryBuilder
                ->andWhere('s.program = :program')
                ->setParameter('program', $program);
        }

        $counts = [];

        foreach ($queryBuilder->getQuery()->getArrayResult() as $row) {
            $counts[(int) $row['section_id']] = (int) $row['course_count'];
        }

        return $counts;
    }
}
