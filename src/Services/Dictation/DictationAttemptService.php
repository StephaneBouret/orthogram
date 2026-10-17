<?php

declare(strict_types=1);

namespace App\Services\Dictation;

use App\Entity\Dictation;
use App\Entity\DictationAttempt;
use App\Entity\DictationVersion;
use App\Entity\User;
use App\Enum\DictationAction;
use App\Enum\DictationState;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Shared transactional workflow with an explicit learner projection. */
final class DictationAttemptService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DictationCourseProgress $progress,
        private readonly DictationCorrector $engine,
        private readonly DictationTokenizer $tokenizer,
    ) {
    }

    /** The caller supplies the authenticated actor, never a user/version from a request body.
     * @return array<string, mixed>
     */
    public function start(User $actor, int $dictationId, bool $learnerView = false): array
    {
        return $this->em->wrapInTransaction(function () use ($actor, $dictationId, $learnerView): array {
            $this->lockActor($actor);
            $dictation = $this->em->find(Dictation::class, $dictationId, LockMode::PESSIMISTIC_READ);
            if (null === $dictation) {
                throw new NotFoundHttpException('Dictée introuvable.');
            }
            $attempt = $this->em->createQueryBuilder()->select('a')->from(DictationAttempt::class, 'a')
                ->where('a.user = :user AND a.dictation = :dictation')->setParameter('user', $actor)->setParameter('dictation', $dictation)
                ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
            $progress = $this->progress->read($actor, $dictation->getSection());
            if (null === $attempt) {
                $version = $this->em->createQueryBuilder()->select('v')->from(DictationVersion::class, 'v')
                    ->where('v.dictation = :dictation AND v.publishedAt IS NOT NULL')->setParameter('dictation', $dictation)
                    ->orderBy('v.number', 'DESC')->setMaxResults(1)->getQuery()
                    ->setLockMode(LockMode::PESSIMISTIC_READ)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
                if (null === $version) {
                    throw new ConflictHttpException('Aucune version éditoriale figée.');
                }
                $attempt = new DictationAttempt($actor, $version, $progress['started']);
                if ($learnerView) {
                    $this->learnerProjection($attempt, $progress['complete']);
                }
                $this->em->persist($attempt);
                $this->em->flush();
            }

            return $learnerView ? $this->learnerProjection($attempt, $progress['complete']) : $this->present($attempt, $progress['complete']);
        });
    }

    /** @return array<string, mixed> */
    public function state(User $actor, int $attemptId, bool $learnerView = false): array
    {
        return $this->em->wrapInTransaction(function () use ($actor, $attemptId, $learnerView): array {
            $this->lockActor($actor);
            $attempt = $this->owned($actor, $attemptId);
            $progress = $this->progress->read($actor, $attempt->getDictation()->getSection());

            return $learnerView ? $this->learnerProjection($attempt, $progress['complete']) : $this->present($attempt, $progress['complete']);
        });
    }

    /** @return array<string, mixed> */
    public function transition(User $actor, int $attemptId, DictationAction $action, int $expectedRevision, ?string $copy = null, bool $learnerView = false): array
    {
        return $this->em->wrapInTransaction(function () use ($actor, $attemptId, $action, $expectedRevision, $copy, $learnerView): array {
            $this->lockActor($actor);
            $attempt = $this->owned($actor, $attemptId);
            $progress = $this->progress->read($actor, $attempt->getDictation()->getSection());
            if ($learnerView) {
                $this->learnerProjection($attempt, $progress['complete']);
            }
            $attempt->transition($action, $expectedRevision, $copy, $progress['complete'], $progress['started'], $this->engine, $this->tokenizer);
            $this->em->flush();

            return $learnerView ? $this->learnerProjection($attempt, $progress['complete']) : $this->present($attempt, $progress['complete']);
        });
    }

    private function lockActor(User $actor): void
    {
        if (null === $actor->getId() || !$this->em->contains($actor)) {
            throw new AccessDeniedHttpException('Utilisateur authentifié persistant requis.');
        }
        $this->em->refresh($actor, LockMode::PESSIMISTIC_WRITE);
        if (!$actor->isAccountActive()) {
            throw new AccessDeniedHttpException('Compte inactif.');
        }
    }

    /** Explicit learner allowlist, checked under the same lock as SAVE (before receipt replay).
     * @return array<string, mixed>
     */
    private function learnerProjection(DictationAttempt $attempt, bool $coursesComplete): array
    {
        $version = $attempt->getVersion();
        $state = $attempt->getState();
        $data = ['attemptId' => $attempt->getId(), 'state' => $state->value, 'revision' => $attempt->getRevision(), 'title' => $version->getTitle()];
        if (in_array($state, [DictationState::AwaitingCourses, DictationState::Reviewing, DictationState::Completed], true)) {
            $summary = $attempt->getClosedResult();
            unset($summary['issues']);
            $data += ['coursesComplete' => $coursesComplete,
                'canResume' => DictationState::AwaitingCourses === $state && $coursesComplete,
                'canFinalize' => DictationState::Reviewing === $state && $coursesComplete,
                'canConsultCorrection' => DictationState::Completed === $state && $coursesComplete];
            if (DictationState::Completed === $state) {
                $data += ['finalCopy' => $attempt->getFinalCopy() ?? $attempt->getClosedCopy(),
                    'finalResult' => $attempt->getFinalResult() ?? $summary,
                    'finalResultSource' => null !== $attempt->getFinalResult() ? 'post_courses' : 'initial_closure'];
                if ($data['canConsultCorrection']) {
                    $data += ['canonicalText' => $version->getCanonicalText(), 'correctionContent' => $version->getCorrectionContent()];
                }

                return $data;
            }

            return $data + ['closedCopy' => $attempt->getClosedCopy(), 'closedResult' => $summary, 'prerequisiteSatisfied' => $attempt->isPrerequisiteSatisfied()];
        }
        // Only these editable states receive the draft, counts and public segment bounds.
        if (!in_array($state, [DictationState::Writing, DictationState::SelfCorrection, DictationState::Resuming], true)) {
            throw new ConflictHttpException('Phase indisponible.');
        }
        $data += ['coursesComplete' => $coursesComplete];
        if (null === $version->getCompleteAudio() || null === $version->getWritingAudio() || [] === $version->getSegments()) {
            throw new ConflictHttpException('Cette version ne dispose pas encore des pistes et segments nécessaires.');
        }

        if (DictationState::SelfCorrection === $state) {
            $data += ['initialCopy' => $attempt->getInitialCopy(), 'initialFeedback' => $attempt->getInitialResult()];
        }

        return $data + ['draft' => $attempt->getDraft(),
            'wordCount' => $this->tokenizer->countWords($attempt->getDraft()),
            'referenceWordCount' => $this->tokenizer->countWords($version->getCanonicalText()),
            'segments' => $version->getSegments()];
    }

    private function owned(User $actor, int $attemptId): DictationAttempt
    {
        $attempt = $this->em->createQueryBuilder()->select('a')->from(DictationAttempt::class, 'a')
            ->where('a.id = :id AND a.user = :user')->setParameter('id', $attemptId)->setParameter('user', $actor)
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
        if (null === $attempt) {
            throw new NotFoundHttpException('Tentative introuvable.');
        }

        return $attempt;
    }

    /** Explicit allowlist: stored feedback is not an entitlement to display it in later phases.
     * @return array<string, mixed>
     */
    private function present(DictationAttempt $attempt, bool $coursesComplete): array
    {
        $state = $attempt->getState();
        $data = ['attemptId' => $attempt->getId(), 'state' => $state->value, 'revision' => $attempt->getRevision(),
            'title' => $attempt->getVersion()->getTitle(), 'latePassage' => $attempt->isLatePassage(),
            'prerequisiteSatisfied' => $attempt->isPrerequisiteSatisfied(), 'coursesComplete' => $coursesComplete,
            'canResume' => DictationState::AwaitingCourses === $state && $coursesComplete,
            'canConsultCorrection' => DictationState::Completed === $state && $coursesComplete,
            'dates' => $attempt->dates()];
        if (in_array($state, [DictationState::Writing, DictationState::SelfCorrection, DictationState::Resuming], true)) {
            $data['draft'] = $attempt->getDraft();
        }
        if (DictationState::SelfCorrection === $state) {
            $data['initialCopy'] = $attempt->getInitialCopy();
            $data['initialFeedback'] = $attempt->getInitialResult();
        }
        if (in_array($state, [DictationState::AwaitingCourses, DictationState::Reviewing, DictationState::Completed], true)) {
            $summary = $attempt->getClosedResult();
            unset($summary['issues']);
            $data['closedCopy'] = $attempt->getClosedCopy();
            $data['closedResult'] = $summary;
        }
        if ($data['canConsultCorrection']) {
            $data['finalCopy'] = $attempt->getFinalCopy() ?? $attempt->getClosedCopy();
            $data['finalResult'] = $attempt->getFinalResult() ?? $data['closedResult'];
            $data['finalResultSource'] = null !== $attempt->getFinalResult() ? 'post_courses' : 'initial_closure';
            $data['correctionContent'] = $attempt->getVersion()->getCorrectionContent();
        }

        return $data;
    }
}
