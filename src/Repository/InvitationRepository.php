<?php

namespace App\Repository;

use App\Entity\Invitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invitation>
 */
class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    public function findValidByToken(string $token): ?Invitation
    {
        $invitation = $this->findOneBy(['token' => $token]);

        if (!$invitation || !$invitation->isValid()) {
            return null;
        }

        return $invitation;
    }

    public function hasBlockingInvitationForEmail(string $email, ?int $excludedId = null): bool
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.email = :email')
            ->setParameter('email', mb_strtolower(trim($email)));

        if ($excludedId !== null) {
            $qb
                ->andWhere('i.id != :excludedId')
                ->setParameter('excludedId', $excludedId);
        }

        /** @var Invitation[] $invitations */
        $invitations = $qb->getQuery()->getResult();

        foreach ($invitations as $invitation) {
            if ($invitation->isUsed() || $invitation->isValid()) {
                return true;
            }
        }

        return false;
    }

    //    /**
    //     * @return Invitation[] Returns an array of Invitation objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('i')
    //            ->andWhere('i.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('i.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Invitation
    //    {
    //        return $this->createQueryBuilder('i')
    //            ->andWhere('i.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
