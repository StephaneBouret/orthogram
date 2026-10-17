<?php

declare(strict_types=1);

namespace App\Tests\Services\Dictation;

use App\Entity\Dictation;
use App\Entity\DictationAttempt;
use App\Entity\DictationVersion;
use App\Entity\Sections;
use App\Entity\User;
use App\Enum\DictationAction as Action;
use App\Enum\DictationState as State;
use App\Services\Dictation\DictationCorrector;
use App\Services\Dictation\DictationTokenizer;
use App\Tests\Support\DictationAudioFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DictationWorkflowTest extends TestCase
{
    private function attempt(): DictationAttempt
    {
        $version = new DictationVersion(new Dictation(new Sections()), 1, 'Test', 'L’école ouvre.', 'Correction synthétique de test.');
        DictationAudioFixture::prepare($version);
        $version->publish(new DictationCorrector(), DictationAudioFixture::storage());

        return new DictationAttempt(new User(), $version, false);
    }

    private function apply(DictationAttempt $attempt, Action $action, ?string $copy = null, bool $complete = false, ?int $revision = null, bool $started = false): void
    {
        $attempt->transition($action, $revision ?? $attempt->getRevision(), $copy, $complete, $started, new DictationCorrector(), new DictationTokenizer());
    }

    public function testNormalPathKeepsExactCopiesAndOnlyRealEvaluations(): void
    {
        $attempt = $this->attempt();
        $copy = "  L’\u{00A0}ouvre.\r\n";
        $this->apply($attempt, Action::Save, $copy);
        self::assertNull($attempt->getInitialResult());
        self::assertSame($copy, $attempt->getDraft());
        $this->apply($attempt, Action::Evaluate, $copy);
        $feedback = $attempt->getInitialResult();
        self::assertSame(State::SelfCorrection, $attempt->getState());
        self::assertSame(66.7, $feedback['score']);
        self::assertFalse($attempt->isPrerequisiteSatisfied());
        $this->apply($attempt, Action::Save, 'L’ecole ouvre.');
        self::assertSame($feedback, $attempt->getInitialResult());
        self::assertSame($copy, $attempt->getInitialCopy());
        $this->apply($attempt, Action::Close, 'L’ecole ouvre.');
        self::assertTrue($attempt->isPrerequisiteSatisfied());
        self::assertArrayNotHasKey('issues', $attempt->getClosedResult());
        $this->apply($attempt, Action::Resume, complete: true);
        self::assertSame('L’ecole ouvre.', $attempt->getDraft());
        $this->apply($attempt, Action::Finalize, "L’e\u{0301}cole ouvre.", true);
        self::assertSame(State::Completed, $attempt->getState());
        self::assertSame("L’e\u{0301}cole ouvre.", $attempt->getFinalCopy());
        self::assertTrue($attempt->getFinalResult()['noErrors']);
        self::assertArrayNotHasKey('issues', $attempt->getFinalResult());
        self::assertSame($feedback, $attempt->getInitialResult());
        self::assertFalse($attempt->isLatePassage());
    }

    public function testPerfectInitialCopyReusesResultWithoutInventingEvaluations(): void
    {
        $a = $this->attempt();
        $this->apply($a, Action::Evaluate, 'L’école ouvre.');
        self::assertSame(State::AwaitingCourses, $a->getState());
        self::assertSame($a->getInitialResult(), $a->getClosedResult());
        $this->apply($a, Action::Resume, complete: true);
        self::assertSame(State::Reviewing, $a->getState());
        $this->apply($a, Action::Finalize, complete: true);
        self::assertNull($a->getFinalCopy());
        self::assertNull($a->getFinalResult());
        self::assertSame(State::Completed, $a->getState());
    }

    public function testAutosaveWinningBeforeFinalizationInvalidatesItsRevision(): void
    {
        $a = $this->attempt();
        $this->apply($a, Action::Evaluate, 'L’ ouvre.');
        $this->apply($a, Action::Close, 'L’ecole ouvre.');
        $this->apply($a, Action::Resume, complete: true);
        $revision = $a->getRevision();
        $this->apply($a, Action::Save, 'nouveau brouillon');
        try {
            $this->apply($a, Action::Finalize, 'ancienne copie', true, $revision);
            self::fail('Une finalisation périmée doit être refusée.');
        } catch (ConflictHttpException) {
            self::assertSame(State::Resuming, $a->getState());
            self::assertSame('nouveau brouillon', $a->getDraft());
            self::assertNull($a->getFinalResult());
        }
    }

    public function testPerfectSelfCorrectionAlsoSkipsNewEvaluationAfterCourses(): void
    {
        $a = $this->attempt();
        $this->apply($a, Action::Evaluate, 'L’ ouvre.');
        $this->apply($a, Action::Close, 'L’école ouvre.');
        $this->apply($a, Action::Resume, complete: true);
        self::assertSame(State::Reviewing, $a->getState());
        $this->apply($a, Action::Finalize, complete: true);
        self::assertNull($a->getFinalResult());
        self::assertSame(66.7, $a->getInitialResult()['score']);
        self::assertSame(100.0, $a->getClosedResult()['score']);
    }

    public function testHundredPercentWithWrongPunctuationDoesNotSkipPhases(): void
    {
        $a = $this->attempt();
        $this->apply($a, Action::Evaluate, 'L’école ouvre');
        self::assertSame(100.0, $a->getInitialResult()['score']);
        self::assertFalse($a->getInitialResult()['noErrors']);
        self::assertSame(State::SelfCorrection, $a->getState());
        $this->apply($a, Action::Close, 'L’école ouvre');
        $this->apply($a, Action::Resume, complete: true);
        self::assertSame(State::Resuming, $a->getState());
    }

    public function testZeroScoreStillClosesPrerequisiteAndDetectsLatePassage(): void
    {
        $a = $this->attempt();
        $this->apply($a, Action::Evaluate, 'x y z', started: true);
        $this->apply($a, Action::Close, 'x y z');
        self::assertSame(0.0, $a->getClosedResult()['score']);
        self::assertTrue($a->isPrerequisiteSatisfied());
        self::assertTrue($a->isLatePassage());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCopies(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => [" \r\n\t\u{00A0}\u{202F}"];
        yield 'invalid UTF8' => ["\xFF"];
        yield 'length limit' => [str_repeat('a', 20001)];
        yield 'unit limit' => [str_repeat('a ', 751)];
    }

    #[DataProvider('invalidCopies')]
    public function testInvalidEvaluationDoesNotMutateAttempt(string $copy): void
    {
        $a = $this->attempt();
        try {
            $this->apply($a, Action::Evaluate, $copy);
            self::fail('Une copie invalide doit être refusée.');
        } catch (\InvalidArgumentException|\JsonException) {
            self::assertSame(0, $a->getRevision());
            self::assertNull($a->getInitialCopy());
            self::assertNull($a->getInitialResult());
        }
    }

    public function testIdenticalRequestsAreIdempotentAndFrozenEvaluationSurvivesReplay(): void
    {
        $a = $this->attempt();
        $this->apply($a, Action::Save, 'L’ ouvre.', revision: 0);
        $this->apply($a, Action::Save, 'L’ ouvre.', revision: 0);
        self::assertSame(1, $a->getRevision());
        $this->apply($a, Action::Evaluate, 'L’ ouvre.', revision: 1);
        $dates = $a->dates();
        $feedback = $a->getInitialResult();
        $this->apply($a, Action::Evaluate, 'L’ ouvre.', revision: 1);
        self::assertSame($dates, $a->dates());
        $this->apply($a, Action::Save, 'L’ecole ouvre.');
        $this->apply($a, Action::Evaluate, 'L’ ouvre.', revision: 1);
        self::assertSame(3, $a->getRevision());
        self::assertSame('L’ecole ouvre.', $a->getDraft());
        self::assertSame($feedback, $a->getInitialResult());
    }

    /** @return iterable<string, array{string}> */
    public static function conflicts(): iterable
    {
        foreach (['stale save', 'contradictory evaluation', 'close twice differently', 'late save', 'resume early', 'finalize early', 'save after completion'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('conflicts')]
    public function testInvalidTransitionsAreExplicit(string $case): void
    {
        $a = $this->attempt();
        $this->apply($a, Action::Evaluate, 'L’ ouvre.');
        if (in_array($case, ['close twice differently', 'late save', 'resume early', 'finalize early', 'save after completion'], true)) {
            $this->apply($a, Action::Close, 'L’ ouvre.');
        }
        if (in_array($case, ['finalize early', 'save after completion'], true)) {
            $this->apply($a, Action::Resume, complete: true);
        }
        if ('save after completion' === $case) {
            $this->apply($a, Action::Finalize, 'L’école ouvre.', true);
        }
        $this->expectException(ConflictHttpException::class);
        match ($case) {
            'stale save' => $this->apply($a, Action::Save, 'autre', revision: 0),
            'contradictory evaluation' => $this->apply($a, Action::Evaluate, 'autre', revision: 0),
            'close twice differently' => $this->apply($a, Action::Close, 'autre', revision: 1),
            'late save', 'save after completion' => $this->apply($a, Action::Save, 'autre'),
            'resume early' => $this->apply($a, Action::Resume),
            'finalize early' => $this->apply($a, Action::Finalize, 'L’école ouvre.'),
            default => throw new \LogicException('Cas de test inconnu.'),
        };
    }

    public function testPublishedVersionCannotBeEdited(): void
    {
        $v = $this->attempt()->getVersion();
        $this->expectException(\DomainException::class);
        $v->edit('Autre', 'Autre texte.', null);
    }

    /** @return iterable<string, array{State, Action}> */
    public static function forbiddenTransitions(): iterable
    {
        $allowed = [
            State::Writing->value => [Action::Save, Action::Evaluate],
            State::SelfCorrection->value => [Action::Save, Action::Close],
            State::AwaitingCourses->value => [Action::Resume],
            State::Resuming->value => [Action::Save, Action::Finalize],
            State::Reviewing->value => [Action::Finalize],
            State::Completed->value => [],
        ];
        foreach (State::cases() as $state) {
            foreach (Action::cases() as $action) {
                if (!in_array($action, $allowed[$state->value], true)) {
                    yield $state->value.'/'.$action->value => [$state, $action];
                }
            }
        }
    }

    #[DataProvider('forbiddenTransitions')]
    public function testEveryForbiddenStateTransition(State $state, Action $action): void
    {
        $a = $this->attempt();
        if (State::Reviewing === $state) {
            $this->apply($a, Action::Evaluate, 'L’école ouvre.');
            $this->apply($a, Action::Resume, complete: true);
        } elseif (State::Writing !== $state) {
            $this->apply($a, Action::Evaluate, 'L’ ouvre.');
            if (State::SelfCorrection !== $state) {
                $this->apply($a, Action::Close, 'L’ ouvre.');
            }
            if (in_array($state, [State::Resuming, State::Completed], true)) {
                $this->apply($a, Action::Resume, complete: true);
            }
            if (State::Completed === $state) {
                $this->apply($a, Action::Finalize, 'L’école ouvre.', true);
            }
        }
        self::assertSame($state, $a->getState());
        $this->expectException(ConflictHttpException::class);
        $this->apply($a, $action, 'autre', true);
    }

    public function testPublishedVersionCannotBeDeleted(): void
    {
        $v = $this->attempt()->getVersion();
        $this->expectException(\DomainException::class);
        $v->protectDeletion();
    }

    public function testUnpublishedVersionCannotStartAttempt(): void
    {
        $v = new DictationVersion(new Dictation(new Sections()), 1, 'Test', 'Mot.');
        $this->expectException(\DomainException::class);
        new DictationAttempt(new User(), $v, false);
    }
}
