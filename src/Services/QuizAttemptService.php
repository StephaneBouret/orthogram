<?php

namespace App\Services;

use App\Entity\Courses;
use App\Entity\Lesson;
use App\Entity\Quiz;
use App\Entity\QuizAttempt;
use App\Entity\QuizQuestion;
use App\Entity\User;
use App\Enum\CourseContentType;
use App\Enum\LessonStatus;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @phpstan-import-type Snapshot from QuizAttempt
 * @phpstan-import-type FrozenQuestion from QuizAttempt
 */
final class QuizAttemptService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QuizCorrectionService $correction,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /** @return array<string, mixed> */
    public function state(User $user, Courses $course, ?int $attemptId = null): array
    {
        $quiz = $this->quiz($course);
        $attempt = null === $attemptId ? $this->latest($user, $course, $quiz) : $this->owned($attemptId, $user, $course, $quiz);

        return null === $attempt
            ? ['attemptId' => null, 'title' => $quiz->getTitle(), 'total' => $quiz->getQuestions()->count(), 'validated' => 0, 'completed' => false]
            : $this->present($attempt);
    }

    /**
     * All writes take the SAME existing user row lock, including the first start.
     * It serializes independent HTTP requests on MySQL; reads after acquisition are
     * refreshed so an earlier identity-map value cannot overwrite committed progress.
     * Unique active/restart keys also protect the two creation invariants.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function mutate(User $user, Courses $course, string $action, array $payload): array
    {
        return $this->em->wrapInTransaction(function () use ($user, $course, $action, $payload): array {
            $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($course);
            $quiz = $this->quiz($course);
            if ('start' === $action) {
                $attempt = $this->latest($user, $course, $quiz) ?? $this->create($user, $course, $quiz);
            } else {
                $id = $payload['attemptId'] ?? null;
                if (!is_int($id) || $id < 1) {
                    throw new BadRequestHttpException('Identifiant de tentative invalide.');
                }
                $attempt = $this->owned($id, $user, $course, $quiz);
                switch ($action) {
                    case 'answer':
                        $this->answer($attempt, $payload);
                        break;
                    case 'finish':
                        $this->finish($attempt, $user, $course);
                        break;
                    case 'restart':
                        if (null === $attempt->getCompletedAt()) {
                            throw new ConflictHttpException('Terminez cette tentative avant de recommencer.');
                        }
                        $successor = $this->em->createQueryBuilder()->select('a')->from(QuizAttempt::class, 'a')
                            ->where('a.previousAttempt = :previous')->setParameter('previous', $attempt)
                            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
                        $attempt = $successor ?? $this->create($user, $course, $quiz, $attempt);
                        break;
                    default:
                        throw new NotFoundHttpException();
                }
            }
            $this->em->flush();

            return $this->present($attempt);
        });
    }

    private function quiz(Courses $course): Quiz
    {
        if (CourseContentType::Quiz !== $course->getContentType() || null === $course->getQuiz()) {
            throw new NotFoundHttpException('Ce quiz n’est plus disponible dans ce cours.');
        }

        return $course->getQuiz();
    }

    private function latest(User $user, Courses $course, Quiz $quiz): ?QuizAttempt
    {
        $query = $this->em->createQueryBuilder()->select('a')->from(QuizAttempt::class, 'a')
            ->where('a.user = :user AND a.course = :course AND a.quiz = :quiz')
            ->setParameter('user', $user)->setParameter('course', $course)->setParameter('quiz', $quiz)
            ->orderBy('a.id', 'DESC')->setMaxResults(1)->getQuery()->setHint(Query::HINT_REFRESH, true);
        if ($this->em->getConnection()->isTransactionActive()) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        return $query->getOneOrNullResult();
    }

    private function owned(int $id, User $user, Courses $course, Quiz $quiz): QuizAttempt
    {
        $attempt = $this->em->find(QuizAttempt::class, $id);
        if (null !== $attempt) {
            $this->em->refresh($attempt, $this->em->getConnection()->isTransactionActive() ? LockMode::PESSIMISTIC_WRITE : null);
        }
        if (null === $attempt || $attempt->getUser()->getId() !== $user->getId()
            || $attempt->getCourse()?->getId() !== $course->getId() || $attempt->getQuiz()?->getId() !== $quiz->getId()) {
            throw new NotFoundHttpException('Tentative introuvable.');
        }

        return $attempt;
    }

    private function create(User $user, Courses $course, Quiz $quiz, ?QuizAttempt $previous = null): QuizAttempt
    {
        if (0 === $quiz->getQuestions()->count() || count($this->validator->validate($quiz)) > 0) {
            throw new ConflictHttpException('Ce quiz est vide ou mal configuré.');
        }
        $questions = $quiz->getQuestions()->toArray();
        usort($questions, static fn (QuizQuestion $a, QuizQuestion $b) => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);
        $frozen = [];
        foreach ($questions as $question) {
            $answers = $question->getAnswers()->toArray();
            usort($answers, static fn ($a, $b) => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);
            $frozen[] = ['id' => (int) $question->getId(), 'title' => $question->getTitle(), 'text' => $question->getText(),
                'multiple' => (bool) $question->isMultiple(), 'theme' => $question->getTheme(), 'explanation' => $question->getExplanation(),
                'answers' => array_map(static fn ($a) => ['id' => (int) $a->getId(), 'content' => $a->getContent(), 'correct' => $a->isCorrect()], $answers)];
        }
        $attempt = new QuizAttempt($user, $course, $quiz, ['version' => 1, 'title' => $quiz->getTitle(), 'questions' => $frozen], $previous);
        $this->em->persist($attempt);

        return $attempt;
    }

    /** @param array<string, mixed> $payload */
    private function answer(QuizAttempt $attempt, array $payload): void
    {
        $id = $payload['questionId'] ?? null;
        $selected = $payload['selectedIds'] ?? null;
        if (!is_int($id) || $id < 1 || !is_array($selected)) {
            throw new BadRequestHttpException('Question ou sélection invalide.');
        }
        $questions = $attempt->getSnapshot()['questions'];
        $index = array_search($id, array_column($questions, 'id'), true);
        if (false === $index) {
            throw new BadRequestHttpException('Cette question n’appartient pas à la tentative.');
        }
        $responses = $attempt->getResponses();
        if ($index > count($responses)) {
            throw new ConflictHttpException('Validez d’abord la question attendue.');
        }
        $question = $questions[$index];
        $correctIds = array_column(array_values(array_filter($question['answers'], static fn ($a) => $a['correct'])), 'id');
        try {
            $correct = $this->correction->correctSelection($question['multiple'], array_column($question['answers'], 'id'), $correctIds, $selected);
        } catch (\InvalidArgumentException $error) {
            throw new BadRequestHttpException($error->getMessage(), $error);
        }
        sort($selected);
        if (isset($responses[$index])) {
            if ($responses[$index]['selectedIds'] !== $selected) {
                throw new ConflictHttpException('Cette réponse a déjà été validée avec une autre sélection.');
            }

            return;
        }
        if (null !== $attempt->getCompletedAt()) {
            throw new ConflictHttpException('Cette tentative est terminée.');
        }
        $attempt->record($selected, $correct);
    }

    private function finish(QuizAttempt $attempt, User $user, Courses $course): void
    {
        if (count($attempt->getResponses()) !== $attempt->getTotal()) {
            throw new ConflictHttpException('Répondez à toutes les questions avant de terminer.');
        }
        $attempt->complete();
        // A locking read sees the latest committed row even under MySQL REPEATABLE READ.
        $lesson = $this->em->createQueryBuilder()->select('l')->from(Lesson::class, 'l')
            ->where('l.user = :user AND l.course = :course')->setParameter('user', $user)->setParameter('course', $course)
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
        if (null === $lesson) {
            $lesson = (new Lesson())->setUser($user)->setCourse($course)->setName($course->getName() ?? 'Quiz');
            $this->em->persist($lesson);
        }
        if (!$lesson->isDone()) {
            $lesson->setStatus(LessonStatus::DONE)->setStudiedAt(new \DateTimeImmutable());
        }
    }

    /** Explicit allowlist: only already validated questions receive correction fields.
     * @return array<string, mixed>
     */
    private function present(QuizAttempt $attempt): array
    {
        $snapshot = $attempt->getSnapshot();
        $responses = $attempt->getResponses();
        $validated = count($responses);
        $review = [];
        foreach ($responses as $index => $response) {
            $review[] = $snapshot['questions'][$index] + $response;
        }
        $question = $snapshot['questions'][$validated] ?? null;
        if (null !== $question) {
            unset($question['explanation']);
            $question['answers'] = array_map(static fn ($a) => ['id' => $a['id'], 'content' => $a['content']], $question['answers']);
        }

        return ['attemptId' => $attempt->getId(), 'title' => $snapshot['title'], 'total' => $attempt->getTotal(),
            'percentage' => QuizScore::percentage($attempt->getScore(), $attempt->getTotal()),
            'validated' => $validated, 'completed' => null !== $attempt->getCompletedAt(), 'score' => $attempt->getScore(),
            'startedAt' => $attempt->getStartedAt()->format(DATE_ATOM), 'completedAt' => $attempt->getCompletedAt()?->format(DATE_ATOM),
            'question' => $question, 'review' => $review];
    }
}
