<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningReminder;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LearningReminder>
 */
final class LearningReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningReminder::class);
    }

    public function findOneByUser(User $user): ?LearningReminder
    {
        return $this->findOneBy([
            'user' => $user,
        ]);
    }
}
