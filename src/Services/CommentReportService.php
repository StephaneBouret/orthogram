<?php

namespace App\Services;

use App\Controller\Admin\CommentReportCrudController;
use App\Entity\Comment;
use App\Entity\CommentReport;
use App\Entity\User;
use App\Repository\CommentReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CommentReportService
{
    public function __construct(
        private readonly CommentReportRepository $commentReportRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SendMailService $sendMailService,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly string $supportEmail,
    ) {}

    public function report(Comment $comment, User $reporter): ?bool
    {
        if ($this->commentReportRepository->findOneByCommentAndReporter($comment, $reporter) instanceof CommentReport) {
            return null;
        }

        $report = (new CommentReport())
            ->setComment($comment)
            ->setReporter($reporter);

        $this->entityManager->persist($report);
        $this->entityManager->flush();

        return $this->notifyAdmin($report);
    }

    private function notifyAdmin(CommentReport $report): bool
    {
        try {
            $this->sendMailService->sendMail(
                'Orthogram',
                $this->supportEmail,
                'Signalement d’un commentaire',
                'comment_report',
                [
                    'report' => $report,
                    'comment' => $report->getComment(),
                    'reporter' => $report->getReporter(),
                    'courseUrl' => $this->generateCourseUrl($report->getComment()),
                    'adminReportUrl' => $this->adminUrlGenerator
                        ->setController(CommentReportCrudController::class)
                        ->setAction(Action::DETAIL)
                        ->setEntityId($report->getId())
                        ->generateUrl(),
                ],
                null
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Erreur lors de l’envoi du signalement de commentaire.', [
                'reportId' => $report->getId(),
                'commentId' => $report->getComment()?->getId(),
                'reporterId' => $report->getReporter()?->getId(),
                'exception' => $exception,
            ]);

            return false;
        }

        return true;
    }

    private function generateCourseUrl(?Comment $comment): ?string
    {
        $course = $comment?->getCourse();

        if ($course === null) {
            return null;
        }

        return $this->urlGenerator->generate('app_course_show', [
            'programSlug' => $course->getSection()?->getProgram()?->getSlug(),
            'sectionSlug' => $course->getSection()?->getSlug(),
            'courseSlug' => $course->getSlug(),
            '_fragment' => 'comments',
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
