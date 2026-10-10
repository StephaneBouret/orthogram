<?php

namespace App\Services\Courses;

use App\Entity\Courses;
use App\Entity\User;
use App\Security\Voter\CourseVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class CourseBookmarkService
{
    public function __construct(
        private readonly TokenStorageInterface $tokens,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function canUse(Courses $course): bool
    {
        $section = $course->getSection();
        $program = $section?->getProgram();

        return $this->tokens->getToken()?->getUser() instanceof User
            && $course->getSlug() && $section?->getSlug() && $program?->getSlug()
            && $this->authorization->isGranted(CourseVoter::INTERACT, $course)
            && $this->authorization->isGranted(CourseVoter::VIEW, $course);
    }

    public function current(): ?Courses
    {
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof User) {
            return null;
        }

        // Read from the database, not the serialized security token. Inner joins
        // also discard a missing course, section or program without lazy errors.
        $course = $this->em->getRepository(Courses::class)->createQueryBuilder('c')
            ->addSelect('s', 'p')
            ->innerJoin('c.section', 's')->innerJoin('s.program', 'p')
            ->innerJoin(User::class, 'u', 'WITH', 'u.lastVisitedCourse = c')
            ->where('u.id = :user')->setParameter('user', $user->getId())
            ->getQuery()->getOneOrNullResult();

        return $course instanceof Courses && $this->canUse($course) ? $course : null;
    }
}
