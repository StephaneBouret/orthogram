<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserDevice;
use App\Enum\DeviceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserDevice>
 */
class UserDeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserDevice::class);
    }

    public function findOneActiveByUuidForUser(string $deviceUuid, User $user): ?UserDevice
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.deviceUuid = :uuid')
            ->andWhere('d.user = :user')
            ->andWhere('d.status = :status')
            ->andWhere('d.revokedAt IS NULL')
            ->setParameter('uuid', $deviceUuid)
            ->setParameter('user', $user)
            ->setParameter('status', DeviceStatus::ACTIVE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByUuidForUser(string $deviceUuid, User $user): ?UserDevice
    {
        return $this->findOneBy([
            'deviceUuid' => $deviceUuid,
            'user' => $user,
        ]);
    }

    /**
     * @return UserDevice[]
     */
    public function findActiveForUser(User $user): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.user = :user')
            ->andWhere('d.status = :status')
            ->andWhere('d.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('status', DeviceStatus::ACTIVE)
            ->orderBy('d.lastUsedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countActiveForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.user = :user')
            ->andWhere('d.status = :status')
            ->andWhere('d.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('status', DeviceStatus::ACTIVE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    //    /**
    //     * @return UserDevice[] Returns an array of UserDevice objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('u.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?UserDevice
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
