<?php

namespace App\Security\Voter;

use App\Entity\Courses;
use App\Entity\Exercice;
use App\Entity\Program;
use App\Entity\Sections;
use App\Entity\Subscription;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<'VIEW_COURSE'|'INTERACT_COURSE'|'SECTION_VIEW'|'PROGRAM_VIEW', Courses|Exercice|Sections|Program>
 */
final class CourseVoter extends Voter
{
    public const VIEW = 'VIEW_COURSE';
    public const INTERACT = 'INTERACT_COURSE';
    public const SECTION_VIEW = 'SECTION_VIEW';
    public const PROGRAM_VIEW = 'PROGRAM_VIEW';

    public function __construct(
        #[Autowire(service: 'security.authentication.trust_resolver')]
        private readonly AuthenticationTrustResolverInterface $trustResolver,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::VIEW => $subject instanceof Courses,
            self::INTERACT => $subject instanceof Courses || $subject instanceof Exercice,
            self::SECTION_VIEW => $subject instanceof Sections,
            self::PROGRAM_VIEW => $subject instanceof Program,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // The application's trust resolver excludes incomplete 2FA sessions.
        if (!$user instanceof User || !$user->isAccountActive()
            || (!$this->trustResolver->isFullFledged($token) && !$this->trustResolver->isRememberMe($token))) {
            return false;
        }

        if ($this->hasElevatedAccess($user)) {
            return true;
        }

        if (self::INTERACT === $attribute && $subject instanceof Exercice) {
            if ($subject->getCourses()->isEmpty()) {
                return $this->hasActiveSubscription($user);
            }

            return $subject->getCourses()->exists(
                fn (int|string $key, Courses $course): bool => $this->canViewCourse($course, $user)
            );
        }

        if (self::VIEW === $attribute || self::INTERACT === $attribute) {
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
