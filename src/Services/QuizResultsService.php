<?php

namespace App\Services;

use App\Entity\Courses;
use App\Entity\QuizAttempt;
use App\Entity\User;
use App\Enum\CourseContentType;
use App\Security\Voter\CourseVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Read-only personal results. Public methods deliberately accept no user identifier. */
final class QuizResultsService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** @return array{sections: array<int, array<string, mixed>>, hasArchives: bool} */
    public function moduleOverview(): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(a.course) AS courseId', 'IDENTITY(a.quiz) AS quizId', 'COUNT(a.completedAt) AS completed')
            ->from(QuizAttempt::class, 'a')->where('a.user = :user')->setParameter('user', $this->currentUser())
            ->groupBy('a.course', 'a.quiz')->getQuery()->getArrayResult();
        $passed = [];
        foreach ($rows as $row) {
            $passed[$row['courseId'].':'.$row['quizId']] = (int) $row['completed'] > 0;
        }

        $sections = [];
        $hasArchives = false;
        foreach ($this->personalCatalog($passed) as $key => $course) {
            $section = $course->getSection();
            if (null === $section) {
                $hasArchives = true;
                continue;
            }
            $id = $section->getId();
            $sections[$id] ??= ['id' => $id, 'name' => $section->getName(), 'passed' => 0, 'total' => 0];
            ++$sections[$id]['total'];
            if ($passed[$key] ?? false) {
                ++$sections[$id]['passed'];
            }
            unset($passed[$key]);
        }

        return ['sections' => $sections, 'hasArchives' => $hasArchives || [] !== $passed];
    }

    /**
     * Personal histories in chronological order (completedAt, id).
     *
     * @return list<array<string, mixed>>
     */
    public function results(?int $sectionId = null, bool $archivesOnly = false): array
    {
        $query = $this->em->createQueryBuilder()
            ->select('a', 'c', 'q', 's', 'p', 'currentQuiz')->from(QuizAttempt::class, 'a')
            ->leftJoin('a.course', 'c')->leftJoin('a.quiz', 'q')
            ->leftJoin('c.section', 's')->leftJoin('s.program', 'p')->leftJoin('c.quiz', 'currentQuiz')
            ->where('a.user = :user')->setParameter('user', $this->currentUser());
        if (null !== $sectionId) {
            $query->andWhere('s.id = :section AND c.contentType = :type AND q.id = currentQuiz.id')
                ->setParameter('section', $sectionId)->setParameter('type', CourseContentType::Quiz);
        }
        $attempts = $query->orderBy('a.completedAt', 'ASC')->addOrderBy('a.id', 'ASC')->getQuery()->getResult();
        $byKey = [];
        foreach ($attempts as $attempt) {
            $byKey[$this->groupKey($attempt)][] = $attempt;
        }

        $results = [];
        foreach ($this->personalCatalog($byKey, $sectionId) as $key => $course) {
            if (!$archivesOnly || null === $course->getSection()) {
                $results[] = $this->group($key, $course, $byKey[$key] ?? []);
            }
            unset($byKey[$key]);
        }
        if (null === $sectionId) {
            // Unmatched attempts belong to historical course/quiz pairs.
            foreach ($byKey as $key => $group) {
                $results[] = $this->group($key, $group[0]->getCourse(), $group);
            }
        }

        return $results;
    }

    /**
     * Ordered current course/quiz pairs, including personal results after access expires.
     *
     * @param array<string, mixed> $owned
     *
     * @return array<string, Courses>
     */
    private function personalCatalog(array $owned, ?int $sectionId = null): array
    {
        $query = $this->em->createQueryBuilder()
            ->select('c', 'q', 's', 'p')->from(Courses::class, 'c')
            ->innerJoin('c.quiz', 'q')->leftJoin('c.section', 's')->leftJoin('s.program', 'p')
            ->where('c.contentType = :type')->setParameter('type', CourseContentType::Quiz);
        if (null !== $sectionId) {
            $query->andWhere('s.id = :section')->setParameter('section', $sectionId);
        }
        $courses = $query->orderBy('p.id', 'ASC')->addOrderBy('s.position', 'ASC')->addOrderBy('s.id', 'ASC')
            ->addOrderBy('c.position', 'ASC')->addOrderBy('c.id', 'ASC')->getQuery()->getResult();
        $catalog = [];
        foreach ($courses as $course) {
            $key = $course->getId().':'.$course->getQuiz()->getId();
            if (isset($owned[$key]) || $this->security->isGranted(CourseVoter::INTERACT, $course)) {
                $catalog[$key] = $course;
            }
        }

        return $catalog;
    }

    /** An owned completed attempt anchors the group, including standalone archives.
     * @return array<string, mixed>
     */
    public function history(int $anchorId, ?int $selectedId = null): array
    {
        $user = $this->currentUser();
        /** @var QuizAttempt|null $anchor */
        $anchor = $this->em->createQueryBuilder()->select('a', 'c', 'q', 's', 'p', 'currentQuiz')
            ->from(QuizAttempt::class, 'a')->leftJoin('a.course', 'c')->leftJoin('a.quiz', 'q')
            ->leftJoin('c.section', 's')->leftJoin('s.program', 'p')->leftJoin('c.quiz', 'currentQuiz')
            ->where('a.id = :id AND a.user = :user AND a.completedAt IS NOT NULL')
            ->setParameter('id', $anchorId)->setParameter('user', $user)->getQuery()->getOneOrNullResult();
        if (null === $anchor) {
            throw new NotFoundHttpException('Historique introuvable.', headers: ['Cache-Control' => 'private, no-store']);
        }
        $attempts = [$anchor];
        if (null !== $anchor->getCourse() && null !== $anchor->getQuiz()) {
            $attempts = $this->em->createQueryBuilder()->select('a')->from(QuizAttempt::class, 'a')
                ->where('a.user = :user AND a.course = :course AND a.quiz = :quiz')
                ->setParameter('user', $user)->setParameter('course', $anchor->getCourse())->setParameter('quiz', $anchor->getQuiz())
                ->orderBy('a.completedAt', 'ASC')->addOrderBy('a.id', 'ASC')->getQuery()->getResult();
        }
        $group = $this->group($this->groupKey($anchor), $anchor->getCourse(), $attempts);
        $selectedId ??= $group['latest']['attemptId'];
        foreach ($group['history'] as $row) {
            if ($selectedId === $row['attemptId']) {
                return $group + ['anchorId' => $anchorId, 'selected' => $row];
            }
        }

        // Never fall back to the latest result for a forged or unfinished selection.
        throw new NotFoundHttpException('Tentative introuvable.', headers: ['Cache-Control' => 'private, no-store']);
    }

    /** Explicit presentation allowlist, built only after ownership and access checks.
     * @return array<string, mixed>
     */
    public function correction(int $id): array
    {
        $user = $this->currentUser();
        /** @var QuizAttempt|null $attempt */
        $attempt = $this->em->createQueryBuilder()->select('a', 'c', 's', 'p')
            ->from(QuizAttempt::class, 'a')->leftJoin('a.course', 'c')
            ->leftJoin('c.section', 's')->leftJoin('s.program', 'p')
            ->where('a.id = :id AND a.user = :user')->setParameter('id', $id)->setParameter('user', $user)
            ->getQuery()->getOneOrNullResult();
        if (null === $attempt || null === $attempt->getCompletedAt() || null === $attempt->getCourse()) {
            throw new NotFoundHttpException('Tentative introuvable.');
        }
        if (!$this->security->isGranted(CourseVoter::INTERACT, $attempt->getCourse())) {
            throw new AccessDeniedException();
        }

        $questions = [];
        $responses = $attempt->getResponses();
        foreach ($attempt->getSnapshot()['questions'] as $index => $question) {
            $response = $responses[$index] ?? null;
            $answers = [];
            foreach ($question['answers'] as $answer) {
                $answers[] = ['content' => $answer['content'], 'correct' => $answer['correct'],
                    'selected' => in_array($answer['id'], $response['selectedIds'] ?? [], true)];
            }
            $questions[] = ['title' => $question['title'], 'text' => $question['text'],
                'theme' => $question['theme'], 'explanation' => $question['explanation'],
                'correct' => $response['correct'] ?? null, 'answers' => $answers];
        }

        return $this->score($attempt) + ['questions' => $questions,
            'historyUrl' => $this->urls->generate('app_quiz_results_history', ['anchor' => $id, 'attempt' => $id])];
    }

    private function currentUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->isAccountActive()) {
            throw new AccessDeniedException();
        }

        return $user;
    }

    /** @param list<QuizAttempt> $attempts
     * @return array<string, mixed>
     */
    private function group(string $key, ?Courses $course, array $attempts): array
    {
        $completed = array_values(array_filter($attempts, static fn (QuizAttempt $a) => null !== $a->getCompletedAt()));
        $active = array_values(array_filter($attempts, static fn (QuizAttempt $a) => null === $a->getCompletedAt() && $a->isInProgress()));
        $latest = [] !== $completed ? $completed[array_key_last($completed)] : null;
        $reference = $latest ?? ([] !== $attempts ? $attempts[array_key_last($attempts)] : null);
        $quizId = null !== $reference ? $reference->getQuiz()?->getId() : $course?->getQuiz()?->getId();
        $current = null !== $course && null !== $quizId && CourseContentType::Quiz === $course->getContentType()
            && $quizId === $course->getQuiz()?->getId();
        $canView = null !== $course && $this->security->isGranted(CourseVoter::VIEW, $course);
        $canInteract = null !== $course && $this->security->isGranted(CourseVoter::INTERACT, $course);
        $previous = null;
        $best = null;
        $mixed = false;
        $history = [];
        foreach ($completed as $attempt) {
            $changed = null !== $previous && !$this->comparable($previous, $attempt);
            $percentage = QuizScore::percentage($attempt->getScore(), $attempt->getTotal());
            $previousPercentage = null !== $previous ? QuizScore::percentage($previous->getScore(), $previous->getTotal()) : null;
            $delta = !$changed && null !== $percentage && null !== $previousPercentage ? $percentage - $previousPercentage : null;
            $history[] = $this->score($attempt) + [
                'contentChanged' => $changed, 'delta' => $delta,
                'deltaLabel' => null === $delta ? null : (0 === $delta ? 'Stable' : sprintf('%+d points', $delta)),
                'correctionUrl' => $canInteract ? $this->urls->generate('app_quiz_result_correction', ['id' => $attempt->getId()]) : null,
            ];
            if ($this->comparable($attempt, $latest)) {
                if (null !== $percentage && (null === $best || $attempt->getScore() * $best->getTotal() > $best->getScore() * $attempt->getTotal())) {
                    $best = $attempt;
                }
            } else {
                $mixed = true;
            }
            $previous = $attempt;
        }
        $section = $course?->getSection();
        $program = $section?->getProgram();
        $courseUrl = $current && $canView && $canInteract && null !== $program
            ? $this->urls->generate('app_course_show', ['programSlug' => $program->getSlug(), 'sectionSlug' => $section->getSlug(), 'courseSlug' => $course->getSlug()]) : null;

        $percentages = array_column($history, 'percentage');
        $gain = count($history) > 1 && !$mixed && !in_array(null, $percentages, true)
            ? $percentages[array_key_last($percentages)] - $percentages[0] : null;

        return [
            'key' => $key, 'courseId' => $course?->getId(), 'quizId' => $quizId,
            'catalogOrder' => [$program?->getId(), $section?->getPosition(), $section?->getId(), $course?->getPosition(), $course?->getId()],
            'title' => null !== $reference ? $reference->getSnapshot()['title'] : $course?->getQuiz()?->getTitle(),
            'section' => null !== $section ? ['id' => $section->getId(), 'name' => $section->getName()] : null,
            'archived' => !$current, 'courseUrl' => $courseUrl,
            'status' => null !== $latest ? 'completed' : ([] !== $active ? 'in_progress' : 'not_started'),
            'hasInProgress' => [] !== $active, 'inProgressAttemptId' => [] !== $active ? $active[array_key_last($active)]->getId() : null,
            'completedCount' => count($completed), 'latest' => [] !== $history ? $history[array_key_last($history)] : null,
            'best' => null !== $best ? $this->score($best) : null, 'mixedContent' => $mixed, 'history' => $history,
            'gain' => $gain,
            'historyUrl' => null !== $latest ? $this->urls->generate('app_quiz_results_history', ['anchor' => $latest->getId()]) : null,
        ];
    }

    private function groupKey(QuizAttempt $attempt): string
    {
        return null !== $attempt->getCourse() && null !== $attempt->getQuiz()
            ? $attempt->getCourse()->getId().':'.$attempt->getQuiz()->getId() : 'archived:'.$attempt->getId();
    }

    private function comparable(QuizAttempt $a, QuizAttempt $b): bool
    {
        // Conservative V1: order, editorial IDs, text, choices and corrections all count.
        // Global title and technical snapshot schema version intentionally do not.
        return $a->getSnapshot()['questions'] === $b->getSnapshot()['questions'];
    }

    /** @return array<string, mixed> */
    private function score(QuizAttempt $attempt): array
    {
        return ['attemptId' => $attempt->getId(), 'title' => $attempt->getSnapshot()['title'],
            'score' => $attempt->getScore(), 'total' => $attempt->getTotal(),
            'percentage' => QuizScore::percentage($attempt->getScore(), $attempt->getTotal()),
            'completedAt' => $attempt->getCompletedAt()];
    }
}
