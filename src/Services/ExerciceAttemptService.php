<?php

namespace App\Services;

use App\Entity\Courses;
use App\Entity\Exercice;
use App\Entity\ExerciceAttempt;
use App\Entity\Lesson;
use App\Entity\User;
use App\Enum\CourseContentType;
use App\Enum\LessonStatus;
use App\Repository\ExerciceAttemptRepository;
use App\Security\Voter\CourseVoter;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ExerciceAttemptService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ExerciceCorrectionService $correction,
        private readonly ExerciceAttemptRepository $attempts,
        private readonly Security $security,
    ) {
    }

    /**
     * @param list<string> $selected
     *
     * @return array<string, mixed>
     */
    public function submit(User $user, Exercice $exercice, array $selected, ?Courses $course): array
    {
        // Same existing-row lock as quizzes: also serializes the first Lesson insert.
        // wrapInTransaction commits before any completed state reaches the controller.
        return $this->em->wrapInTransaction(function () use ($user, $exercice, $selected, $course): array {
            $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($exercice);
            if (null !== $course) {
                $this->em->refresh($course);
                if (CourseContentType::Exercise !== $course->getContentType() || $course->getExercice()?->getId() !== $exercice->getId()) {
                    throw new BadRequestHttpException('Ce cours ne correspond pas à cet exercice.');
                }
                if (!$this->security->isGranted(CourseVoter::INTERACT, $course)) {
                    throw new AccessDeniedHttpException('Vous n’avez pas accès à ce cours.');
                }
            }

            $result = $this->correction->correctClickWords($exercice, $selected);
            $attempt = (new ExerciceAttempt())->setUser($user)->setExercice($exercice)
                ->setScore($result['score'])->setTotal($result['total'])->setPercentage($result['percentage'])
                ->setSelectedTokenIds($selected)->setCorrectionItems($result['items']);
            $this->em->persist($attempt);

            $lesson = null;
            if (null !== $course) {
                // Current read, including when another submission committed while we waited.
                $lesson = $this->em->createQueryBuilder()->select('l')->from(Lesson::class, 'l')
                    ->where('l.user = :user AND l.course = :course')->setParameter('user', $user)->setParameter('course', $course)
                    ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
                if ($this->correction->isSuccessful($exercice, $result) && !($lesson?->isDone() ?? false)) {
                    if (null === $lesson) {
                        $lesson = (new Lesson())->setUser($user)->setCourse($course)->setName($course->getName() ?? 'Exercice');
                        $this->em->persist($lesson);
                    }
                    $lesson->setStatus(LessonStatus::DONE)->setStudiedAt(new \DateTimeImmutable());
                }
            }
            $this->em->flush();
            $result['attempt'] = [
                'id' => $attempt->getId(),
                'number' => $this->attempts->countByUserAndExercice($user, $exercice),
                'submittedAt' => $attempt->getSubmittedAt()->format(\DateTimeInterface::ATOM),
            ];
            $result['courseCompleted'] = $lesson?->isDone() ?? false;

            return $result;
        });
    }
}
