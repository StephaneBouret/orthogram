<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use App\Controller\Course\DictationController;
use App\Entity\Courses;
use App\Entity\Program;
use App\Entity\Sections;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SubscriptionStatus;
use App\EventListener\DictationEnrollmentListener;
use App\Security\Voter\CourseVoter;
use App\Services\Courses\SectionCompletionService;
use App\Services\Dictation\DictationAttemptService;
use App\Services\Dictation\DictationAudioStorage;
use App\Services\Dictation\DictationCorrector;
use App\Services\Dictation\DictationCourseProgress;
use App\Services\Dictation\DictationJourney;
use App\Services\Dictation\DictationTokenizer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Scheb\TwoFactorBundle\Security\Authentication\AuthenticationTrustResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolver as SymfonyTrustResolver;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/** No kernel, application environment, SQL connection or media writes. */
final class DeferredDictationAccessTest extends TestCase
{
    public function testDisabledLearnerActionsStopBeforeDatabaseAndUnavailableStorage(): void
    {
        $em = $this->noDatabase();
        $absent = dirname(__DIR__, 2).'/var/absent-dictation-test-'.bin2hex(random_bytes(8));
        self::assertDirectoryDoesNotExist($absent);
        $attempts = new DictationAttemptService($em,
            new DictationCourseProgress($em, new SectionCompletionService()),
            new DictationCorrector(), new DictationTokenizer());
        $controller = new DictationController($em, $attempts,
            new DictationAudioStorage($absent, dirname(__DIR__, 2).'/public'), new NullLogger(), false);
        $journey = new DictationJourney($em, false);
        $request = Request::create('/unused', 'POST');
        $responses = [$controller->entry(1, $journey), $controller->start(1, $request),
            $controller->write(1), $controller->audio(1, 'complete'), $controller->audio(1, 'writing')];
        foreach (['save', 'evaluate', 'close', 'resume', 'finalize'] as $action) {
            $responses[] = $controller->$action(1, $request);
        }
        foreach ($responses as $response) {
            self::assertSame(404, $response->getStatusCode());
        }
        self::assertDirectoryDoesNotExist($absent);
    }

    public function testDisabledJourneyPreservesCourseRightsAndEnrollmentExemption(): void
    {
        $em = $this->noDatabase();
        $journey = new DictationJourney($em, false);
        $user = new User();
        $user->enrollDictationJourney([3]);
        $section = (new Sections())->setProgram(new Program());
        // Non-null IDs ensure absence of database reads is due to the flag.
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 1);
        (new \ReflectionProperty(Sections::class, 'id'))->setValue($section, 2);
        self::assertNull($journey->forSection($user, $section));
        self::assertFalse($journey->isLocked($user, $section));
        $voter = new CourseVoter(new AuthenticationTrustResolver(new SymfonyTrustResolver()), $journey);
        $course = (new Courses())->setSection($section)->setIsFree(false);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $course, [CourseVoter::VIEW]));
        $user->addSubscription((new Subscription())->setStatus(SubscriptionStatus::ACTIVE)->setIsLifetime(true));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $course, [CourseVoter::VIEW]));
        $newUser = new User();
        (new DictationEnrollmentListener(false))->prePersist(new PrePersistEventArgs($newUser, $em));
        self::assertNull($newUser->getDictationPrerequisites());
    }

    private function noDatabase(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        foreach (['getRepository', 'find', 'getConnection', 'createQueryBuilder', 'wrapInTransaction', 'persist', 'flush'] as $method) {
            $em->expects(self::never())->method($method);
        }

        return $em;
    }
}
