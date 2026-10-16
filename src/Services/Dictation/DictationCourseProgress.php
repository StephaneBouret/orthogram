<?php

declare(strict_types=1);

namespace App\Services\Dictation;

use App\Entity\Courses;
use App\Entity\Lesson;
use App\Entity\Sections;
use App\Entity\User;
use App\Services\Courses\SectionCompletionService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

final class DictationCourseProgress
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly SectionCompletionService $completion)
    {
    }

    /** Current locking reads, including lessons previously hydrated in this request.
     * @return array{complete: bool, started: bool}
     */
    public function read(User $user, Sections $section): array
    {
        $courses = $this->em->createQueryBuilder()->select('c')->from(Courses::class, 'c')
            ->where('c.section = :section')->setParameter('section', $section)
            ->orderBy('c.id', 'ASC')->getQuery()->setLockMode(LockMode::PESSIMISTIC_READ)
            ->setHint(Query::HINT_REFRESH, true)->getResult();
        $lessons = $this->em->createQueryBuilder()->select('l')->from(Lesson::class, 'l')
            ->innerJoin('l.course', 'c')->where('c.section = :section AND l.user = :user')
            ->setParameter('section', $section)->setParameter('user', $user)
            ->orderBy('l.id', 'ASC')->getQuery()->setLockMode(LockMode::PESSIMISTIC_READ)
            ->setHint(Query::HINT_REFRESH, true)->getResult();
        $doneIds = [];
        foreach ($lessons as $lesson) {
            if ($lesson->isDone() && null !== $lesson->getCourse()?->getId()) {
                $doneIds[] = $lesson->getCourse()->getId();
            }
        }
        $bookmark = $user->getLastVisitedCourse();
        if (null !== $bookmark) {
            $this->em->refresh($bookmark);
        }

        return ['complete' => $this->completion->areCoursesComplete($courses, $doneIds),
            'started' => [] !== $lessons || (null !== $bookmark && $bookmark->getSection()?->getId() === $section->getId())];
    }
}
