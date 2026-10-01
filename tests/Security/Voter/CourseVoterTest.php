<?php

namespace App\Tests\Security\Voter;

use App\Entity\Courses;
use App\Entity\Exercice;
use App\Entity\Program;
use App\Entity\Sections;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SubscriptionStatus;
use App\Enum\UserAccountStatus;
use App\Security\Voter\CourseVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\Security\Authentication\AuthenticationTrustResolver;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorToken;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolver as SymfonyTrustResolver;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class CourseVoterTest extends TestCase
{
    #[DataProvider('profiles')]
    public function testPrivateRightsIgnoreFreeFlag(string $profile, bool $allowed): void
    {
        $user = (new User())->setEmail('voter@example.test')->setRoles('admin' === $profile ? ['ROLE_ADMIN'] : []);
        if (in_array($profile, ['active', 'expired', 'future', 'lifetime'], true)) {
            $user->addSubscription((new Subscription())->setStatus(SubscriptionStatus::ACTIVE)
                ->setStartsAt(new \DateTimeImmutable('future' === $profile ? '+1 day' : '-2 days'))
                ->setEndsAt(new \DateTimeImmutable('expired' === $profile ? '-1 day' : '+2 days'))
                ->setIsLifetime('lifetime' === $profile));
        }
        $token = 'anonymous' === $profile ? new NullToken() : new UsernamePasswordToken($user, 'main', $user->getRoles());
        $program = new Program();
        $section = (new Sections())->setProgram($program);
        $course = (new Courses())->setSection($section);
        $linked = new Exercice();
        $linked->getCourses()->add($course);
        $voter = $this->voter();
        foreach ([false, true] as $free) {
            $course->setIsFree($free);
            foreach ([[CourseVoter::VIEW, $course], [CourseVoter::INTERACT, $course],
                [CourseVoter::INTERACT, $linked], [CourseVoter::INTERACT, new Exercice()],
                [CourseVoter::SECTION_VIEW, $section], [CourseVoter::PROGRAM_VIEW, $program]] as [$attribute, $subject]) {
                self::assertSame($allowed ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED, $voter->vote($token, $subject, [$attribute]), $attribute);
            }
        }
    }

    public static function profiles(): iterable
    {
        foreach (['anonymous', 'none', 'expired', 'future', 'active', 'lifetime', 'admin'] as $profile) {
            yield $profile => [$profile, in_array($profile, ['active', 'lifetime', 'admin'], true)];
        }
    }

    public function testInactiveAndIncompleteTwoFactorAreDeniedButRememberMeWorks(): void
    {
        foreach ([[], ['ROLE_ADMIN']] as $roles) {
            $user = (new User())->setEmail('auth@example.test')->setRoles($roles);
            $user->addSubscription((new Subscription())->setStatus(SubscriptionStatus::ACTIVE)->setIsLifetime(true));
            $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
            $program = new Program();
            $section = (new Sections())->setProgram($program);
            $course = (new Courses())->setSection($section)->setIsFree(true);
            foreach ([[CourseVoter::VIEW, $course], [CourseVoter::INTERACT, $course],
                [CourseVoter::INTERACT, new Exercice()], [CourseVoter::SECTION_VIEW, $section],
                [CourseVoter::PROGRAM_VIEW, $program]] as [$attribute, $subject]) {
                $user->setAccountStatus(UserAccountStatus::ACTIVE);
                self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote(new TwoFactorToken($token, null, 'main', ['email']), $subject, [$attribute]));
                self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter()->vote(new RememberMeToken($user, 'main'), $subject, [$attribute]));
                foreach (UserAccountStatus::cases() as $status) {
                    if (UserAccountStatus::ACTIVE === $status) {
                        continue;
                    }
                    $user->setAccountStatus($status);
                    self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($token, $subject, [$attribute]));
                }
            }
        }
    }

    public function testAttributesOnlySupportTheirExactSubjectTypes(): void
    {
        $user = (new User())->setRoles(['ROLE_ADMIN']);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        foreach ([CourseVoter::VIEW, CourseVoter::INTERACT, CourseVoter::SECTION_VIEW, CourseVoter::PROGRAM_VIEW, 'UNKNOWN'] as $attribute) {
            foreach ([new Courses(), new Exercice(), new Sections(), new Program(), new User(), null] as $subject) {
                $supported = match ($attribute) {
                    CourseVoter::VIEW => $subject instanceof Courses,
                    CourseVoter::INTERACT => $subject instanceof Courses || $subject instanceof Exercice,
                    CourseVoter::SECTION_VIEW => $subject instanceof Sections,
                    CourseVoter::PROGRAM_VIEW => $subject instanceof Program,
                    default => false,
                };
                self::assertSame($supported ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_ABSTAIN, $this->voter()->vote($token, $subject, [$attribute]));
            }
        }
    }

    private function voter(): CourseVoter
    {
        return new CourseVoter(new AuthenticationTrustResolver(new SymfonyTrustResolver()));
    }
}
