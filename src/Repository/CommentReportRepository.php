<?php

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\CommentReport;
use App\Entity\User;
use App\Enum\CommentReportStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CommentReport>
 */
class CommentReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommentReport::class);
    }

    public function findOneByCommentAndReporter(Comment $comment, User $reporter): ?CommentReport
    {
        return $this->findOneBy([
            'comment' => $comment,
            'reporter' => $reporter,
        ]);
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.status = :status')
            ->setParameter('status', CommentReportStatus::PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
