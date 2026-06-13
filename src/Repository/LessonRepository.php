<?php

namespace App\Repository;

use App\Entity\Courses;
use App\Entity\Lesson;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\LessonStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Lesson>
 */
class LessonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Lesson::class);
    }

    public function findOneByUserAndCourse(User $user, Courses $course): ?Lesson
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.user = :user')
            ->andWhere('l.course = :course')
            ->setParameter('user', $user)
            ->setParameter('course', $course)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countDoneByUserAndProgram(User $user, Program $program): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->innerJoin('l.course', 'c')
            ->innerJoin('c.section', 's')
            ->andWhere('l.user = :user')
            ->andWhere('l.status = :status')
            ->andWhere('s.program = :program')
            ->setParameter('user', $user)
            ->setParameter('status', LessonStatus::DONE)
            ->setParameter('program', $program)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<int>
     */
    public function findDoneCourseIdsByUserAndProgram(User $user, Program $program): array
    {
        return array_map(
            static fn (mixed $courseId): int => (int) $courseId,
            $this->createQueryBuilder('l')
                ->select('IDENTITY(l.course)')
                ->innerJoin('l.course', 'c')
                ->innerJoin('c.section', 's')
                ->andWhere('l.user = :user')
                ->andWhere('l.status = :status')
                ->andWhere('s.program = :program')
                ->setParameter('user', $user)
                ->setParameter('status', LessonStatus::DONE)
                ->setParameter('program', $program)
                ->getQuery()
                ->getSingleColumnResult()
        );
    }
}
