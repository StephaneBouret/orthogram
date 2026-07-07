<?php

namespace App\Security\Voter;

use App\Entity\Courses;
use App\Entity\Program;
use App\Entity\Sections;
use App\Entity\Subscription;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<'VIEW_COURSE'|'SECTION_VIEW'|'PROGRAM_VIEW', Courses|Sections|Program>
 */
final class CourseVoter extends Voter
{
    public const VIEW = 'VIEW_COURSE';
    public const SECTION_VIEW = 'SECTION_VIEW';
    public const PROGRAM_VIEW = 'PROGRAM_VIEW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::SECTION_VIEW, self::PROGRAM_VIEW], true)
            && ($subject instanceof Courses || $subject instanceof Sections || $subject instanceof Program);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if ($this->hasElevatedAccess($user)) {
            return true;
        }

        if (!$user->isAccountActive()) {
            return false;
        }

        if (self::VIEW === $attribute) {
            return $subject instanceof Courses && $this->canViewCourse($subject, $user);
        }

        if (self::SECTION_VIEW === $attribute) {
            return $subject instanceof Sections && $this->canViewSection($subject, $user);
        }

        return $subject instanceof Program && $this->canViewProgram($subject, $user);
    }

    private function canViewCourse(Courses $course, User $user): bool
    {
        $program = $course->getSection()?->getProgram();

        return $program instanceof Program && $this->canViewProgram($program, $user);
    }

    private function canViewSection(Sections $section, User $user): bool
    {
        $program = $section->getProgram();

        return $program instanceof Program && $this->canViewProgram($program, $user);
    }

    private function canViewProgram(Program $program, User $user): bool
    {
        return $this->hasActiveSubscription($user);
    }

    private function hasElevatedAccess(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true);
    }

    private function hasActiveSubscription(User $user): bool
    {
        return $user->getSubscriptions()->exists(
            static fn (int|string $key, Subscription $subscription): bool => $subscription->isActive()
        );
    }
}
