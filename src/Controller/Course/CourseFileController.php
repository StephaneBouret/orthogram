<?php

declare(strict_types=1);

namespace App\Controller\Course;

use App\Entity\Courses;
use App\Enum\CourseFileKind;
use App\Security\Voter\CourseVoter;
use App\Services\Courses\CourseFileStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class CourseFileController extends AbstractController
{
    #[Route('/courses/{id}/media/{kind}', name: 'app_course_media', requirements: ['id' => '\d+', 'kind' => 'audio|video'], methods: ['GET', 'HEAD'])]
    public function media(Courses $course, string $kind, Request $request, CourseFileStorage $storage): BinaryFileResponse
    {
        $this->denyAccessUnlessGranted(CourseVoter::VIEW, $course);
        $category = CourseFileKind::from($kind);
        if (!$category->matches($course)) {
            throw $this->createNotFoundException();
        }

        return $this->respond($course, $category, $request, $storage, false);
    }

    #[Route('/admin/course-files/{id}/{kind}', name: 'admin_course_file', requirements: ['id' => '\d+', 'kind' => 'source|audio|video'], methods: ['GET', 'HEAD'])]
    public function download(Courses $course, string $kind, Request $request, CourseFileStorage $storage): BinaryFileResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        // Includes active-account and complete authentication checks, including 2FA.
        $this->denyAccessUnlessGranted(CourseVoter::VIEW, $course);

        // Administrators can retrieve an associated old file even after a content-type change.
        return $this->respond($course, CourseFileKind::from($kind), $request, $storage, true);
    }

    private function respond(Courses $course, CourseFileKind $kind, Request $request, CourseFileStorage $storage, bool $download): BinaryFileResponse
    {
        $path = $storage->resolve($course, $kind);
        if (null === $path) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path, public: false);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if ($download) {
            $response->headers->set('Content-Type', 'application/octet-stream');
        }
        $response->setContentDisposition(
            $download ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            (string) $kind->filename($course),
        );
        // Evaluate validators only AFTER all authorization and association checks.
        $response->isNotModified($request);

        return $response;
    }
}
