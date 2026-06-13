<?php

namespace App\Controller\Course;

use App\Entity\Courses;
use App\Entity\Lesson;
use App\Entity\User;
use App\Enum\LessonStatus;
use App\Repository\LessonRepository;
use App\Security\Voter\CourseVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CourseConfirmationController extends AbstractController
{
    public function __construct(
        private readonly LessonRepository $lessonRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[IsGranted('ROLE_USER')]
    #[Route('/course/confirmation/{id}', name: 'app_course_confirmation', methods: ['POST'])]
    public function __invoke(Courses $course, Request $request): Response
    {
        $this->denyAccessUnlessGranted(CourseVoter::VIEW, $course, "Vous n'avez pas accès à ce cours.");

        if (!$this->isCsrfTokenValid('lesson_toggle_' . $course->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Le jeton CSRF est invalide.');
        }

        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Vous devez être connecté pour valider une leçon.');
        }

        $lesson = $this->lessonRepository->findOneByUserAndCourse($user, $course);
        $now = new \DateTimeImmutable();

        if ($lesson === null) {
            $lesson = (new Lesson())
                ->setName($course->getName() ?? 'Cours sans nom')
                ->setCourse($course)
                ->setUser($user);

            $this->entityManager->persist($lesson);
        }

        $lesson
            ->setStatus($lesson->isDone() ? LessonStatus::STUDY : LessonStatus::DONE)
            ->setStudiedAt($now);

        $this->entityManager->flush();

        return $this->redirectToRoute('app_course_show', [
            'programSlug' => $course->getSection()?->getProgram()?->getSlug(),
            'sectionSlug' => $course->getSection()?->getSlug(),
            'courseSlug' => $course->getSlug(),
        ]);
    }
}
