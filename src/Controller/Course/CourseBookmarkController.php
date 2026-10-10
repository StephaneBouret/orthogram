<?php

namespace App\Controller\Course;

use App\Entity\Courses;
use App\Entity\User;
use App\Services\Courses\CourseBookmarkService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CourseBookmarkController extends AbstractController
{
    #[Route('/course/{id}/bookmark', name: 'app_course_bookmark', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function __invoke(Courses $course, Request $request, CourseBookmarkService $bookmarks, EntityManagerInterface $em): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $user = $this->getUser();
        if (!$user instanceof User || !$bookmarks->canUse($course)
            || !$this->isCsrfTokenValid('course_bookmark_'.$user->getId().'_'.$course->getId(), $request->headers->get('X-CSRF-TOKEN'))) {
            return new Response('', Response::HTTP_FORBIDDEN, $headers);
        }

        $version = $request->headers->get('X-Course-Bookmark-Version', '');
        if (!preg_match('/^(0|[1-9][0-9]{0,9})$/D', $version) || (int) $version >= 2147483647) {
            return new Response('', Response::HTTP_BAD_REQUEST, $headers);
        }

        // Compare and increment in the same SQL statement: an old request can
        // never overwrite a newer committed version, even after a disconnect.
        $connection = $em->getConnection();
        $updated = $connection->executeStatement(
            'UPDATE user SET last_visited_course_id = ?, course_bookmark_version = course_bookmark_version + 1 WHERE id = ? AND course_bookmark_version = ?',
            [$course->getId(), $user->getId(), (int) $version],
        );
        $headers['X-Course-Bookmark-Version'] = (string) (1 === $updated ? (int) $version + 1
            : $connection->fetchOne('SELECT course_bookmark_version FROM user WHERE id = ?', [$user->getId()]));
        if (1 === $updated) {
            // Keep Doctrine's snapshot consistent with the direct atomic update.
            $em->refresh($user);
        }

        return new Response('', 1 === $updated ? Response::HTTP_NO_CONTENT : Response::HTTP_CONFLICT, $headers);
    }
}
