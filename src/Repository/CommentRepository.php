<?php

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\Courses;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Comment>
 */
class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /**
     * @return list<Comment>
     */
    public function findRootCommentsByCourse(Courses $course): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')
            ->addSelect('u')
            ->leftJoin('u.avatar', 'a')
            ->addSelect('a')
            ->leftJoin('c.replies', 'r')
            ->addSelect('r')
            ->leftJoin('c.likes', 'cl')
            ->addSelect('cl')
            ->leftJoin('r.user', 'ru')
            ->addSelect('ru')
            ->leftJoin('ru.avatar', 'ra')
            ->addSelect('ra')
            ->leftJoin('r.likes', 'rl')
            ->addSelect('rl')
            ->andWhere('c.course = :course')
            ->andWhere('c.parent IS NULL')
            ->andWhere('c.isHidden = false')
            ->setParameter('course', $course)
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->addOrderBy('r.createdAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByCourse(Courses $course): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.course = :course')
            ->andWhere('c.isHidden = false')
            ->setParameter('course', $course)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findRootByUserAndCourse(User $user, Courses $course): ?Comment
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->andWhere('c.course = :course')
            ->andWhere('c.parent IS NULL')
            ->setParameter('user', $user)
            ->setParameter('course', $course)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
