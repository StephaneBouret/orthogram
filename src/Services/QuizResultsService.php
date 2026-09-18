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

    /**
     * Two fetch-joined queries, independent of the number of attempts. History is
     * chronological (completedAt, id); no questions or responses leave this method.
     *
     * @return list<array<string, mixed>>
     */
    public function results(): array
    {
        $user = $this->currentUser();
        /** @var list<QuizAttempt> $attempts */
        $attempts = $this->em->createQueryBuilder()
            ->select('a', 'c', 'q', 's', 'p', 'currentQuiz')->from(QuizAttempt::class, 'a')
            ->leftJoin('a.course', 'c')->leftJoin('a.quiz', 'q')
            ->leftJoin('c.section', 's')->leftJoin('s.program', 'p')->leftJoin('c.quiz', 'currentQuiz')
            ->where('a.user = :user')->setParameter('user', $user)
            ->orderBy('a.completedAt', 'ASC')->addOrderBy('a.id', 'ASC')->getQuery()->getResult();
        /** @var list<Courses> $courses */
        $courses = $this->em->createQueryBuilder()
            ->select('c', 'q', 's', 'p')->from(Courses::class, 'c')
            ->innerJoin('c.quiz', 'q')->leftJoin('c.section', 's')->leftJoin('s.program', 'p')
            ->where('c.contentType = :type')->setParameter('type', CourseContentType::Quiz)
            ->orderBy('p.id', 'ASC')->addOrderBy('s.position', 'ASC')->addOrderBy('s.id', 'ASC')
            ->addOrderBy('c.position', 'ASC')->addOrderBy('c.id', 'ASC')->getQuery()->getResult();

        $groups = [];
        foreach ($courses as $course) {
            if ($this->security->isGranted(CourseVoter::VIEW, $course)) {
                $key = $course->getId().':'.$course->getQuiz()->getId();
                $groups[$key] = ['course' => $course, 'attempts' => []];
            }
        }
        foreach ($attempts as $attempt) {
            $course = $attempt->getCourse();
            $quiz = $attempt->getQuiz();
            // Missing associations cannot establish editorial identity, even if titles match.
            $key = null !== $course && null !== $quiz
                ? $course->getId().':'.$quiz->getId() : 'archived:'.$attempt->getId();
            $groups[$key] ??= ['course' => $course, 'attempts' => []];
            $groups[$key]['attempts'][] = $attempt;
        }

        $results = [];
        foreach ($groups as $key => $group) {
            $results[] = $this->group((string) $key, $group['course'], $group['attempts']);
        }

        return $results;
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
        if (!$this->security->isGranted(CourseVoter::VIEW, $attempt->getCourse())) {
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

        return $this->score($attempt) + ['questions' => $questions];
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
                'correctionUrl' => $canView ? $this->urls->generate('app_quiz_result_correction', ['id' => $attempt->getId()]) : null,
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
        $courseUrl = $current && $canView && null !== $program
            ? $this->urls->generate('app_course_show', ['programSlug' => $program->getSlug(), 'sectionSlug' => $section->getSlug(), 'courseSlug' => $course->getSlug()]) : null;

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
        ];
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
