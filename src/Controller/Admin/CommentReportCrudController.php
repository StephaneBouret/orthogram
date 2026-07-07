<?php

namespace App\Controller\Admin;

use App\Entity\Comment;
use App\Entity\CommentReport;
use App\Entity\User;
use App\Enum\CommentReportStatus;
use App\Repository\CommentReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\RedirectResponse;

class CommentReportCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return CommentReport::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityPermission('ROLE_ADMIN')
            ->setEntityLabelInSingular('Signalement')
            ->setEntityLabelInPlural('Signalements')
            ->setPageTitle(Crud::PAGE_INDEX, 'Signalements de commentaires')
            ->setPageTitle(Crud::PAGE_DETAIL, fn (CommentReport $report) => sprintf('Signalement #%d', $report->getId()))
            ->setDefaultSort(['status' => 'ASC', 'createdAt' => 'DESC'])
            ->setPaginatorPageSize(20);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('statusLabel', 'Statut')
                ->formatValue(fn (mixed $value, CommentReport $report): string => $this->formatStatusBadge($report))
                ->renderAsHtml()
                ->hideOnForm(),
            AssociationField::new('comment', 'Commentaire')
                ->hideOnForm(),
            AssociationField::new('reporter', 'Signalé par')
                ->formatValue(fn (mixed $value, CommentReport $report) => $report->getReporter()?->getFullname() ?? 'Utilisateur inconnu')
                ->hideOnForm(),
            TextareaField::new('commentContent', 'Contenu signalé')
                ->hideOnForm(),
            DateTimeField::new('createdAt', 'Signalé le')
                ->hideOnForm(),
            DateTimeField::new('resolvedAt', 'Résolu le')
                ->hideOnForm(),
            AssociationField::new('resolvedBy', 'Résolu par')
                ->hideOnForm(),
        ];
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('status')
            ->add('reporter')
            ->add('createdAt')
            ->add('resolvedAt');
    }

    public function configureActions(Actions $actions): Actions
    {
        $markReviewed = Action::new('markReviewed', 'Marquer traité', 'fa fa-check')
            ->linkToCrudAction('markReviewed')
            ->displayIf(static fn (CommentReport $report): bool => $report->isPending())
            ->addCssClass('btn btn-success');

        $dismiss = Action::new('dismiss', 'Rejeter', 'fa fa-xmark')
            ->linkToCrudAction('dismiss')
            ->displayIf(static fn (CommentReport $report): bool => $report->isPending())
            ->addCssClass('btn btn-secondary');

        $hideComment = Action::new('hideReportedComment', 'Masquer le commentaire', 'fa fa-eye-slash')
            ->linkToCrudAction('hideReportedComment')
            ->displayIf(static fn (CommentReport $report): bool => $report->getComment() instanceof Comment && !$report->getComment()->isHidden())
            ->addCssClass('btn btn-warning');

        return $actions
            ->remove(Crud::PAGE_INDEX, Action::NEW)
            ->remove(Crud::PAGE_INDEX, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $hideComment)
            ->add(Crud::PAGE_INDEX, $markReviewed)
            ->add(Crud::PAGE_INDEX, $dismiss)
            ->add(Crud::PAGE_DETAIL, $hideComment)
            ->add(Crud::PAGE_DETAIL, $markReviewed)
            ->add(Crud::PAGE_DETAIL, $dismiss);
    }

    #[AdminRoute(path: '/mark-reviewed', name: 'mark_reviewed')]
    public function markReviewed(
        AdminContext $context,
        CommentReportRepository $commentReportRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $report = $this->getReportFromContext($context, $commentReportRepository);

        if (!$report instanceof CommentReport) {
            return $this->redirectToReportIndex();
        }

        $this->resolveReport($report, CommentReportStatus::REVIEWED);
        $entityManager->flush();

        $this->addFlash('success', 'Le signalement est marqué comme traité.');

        return $this->redirectToReportIndex();
    }

    #[AdminRoute(path: '/dismiss', name: 'dismiss')]
    public function dismiss(
        AdminContext $context,
        CommentReportRepository $commentReportRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $report = $this->getReportFromContext($context, $commentReportRepository);

        if (!$report instanceof CommentReport) {
            return $this->redirectToReportIndex();
        }

        $this->resolveReport($report, CommentReportStatus::DISMISSED);
        $entityManager->flush();

        $this->addFlash('success', 'Le signalement est rejeté.');

        return $this->redirectToReportIndex();
    }

    #[AdminRoute(path: '/hide-reported-comment', name: 'hide_reported_comment')]
    public function hideReportedComment(
        AdminContext $context,
        CommentReportRepository $commentReportRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $report = $this->getReportFromContext($context, $commentReportRepository);

        if (!$report instanceof CommentReport) {
            return $this->redirectToReportIndex();
        }

        $user = $this->getUser();
        $report->getComment()?->hide($user instanceof User ? $user : null);
        $this->resolveReport($report, CommentReportStatus::REVIEWED);
        $entityManager->flush();

        $this->addFlash('success', 'Le commentaire est masqué et le signalement est traité.');

        return $this->redirectToReportIndex();
    }

    private function getReportFromContext(AdminContext $context, CommentReportRepository $commentReportRepository): ?CommentReport
    {
        $entityId = $context->getRequest()->query->get('entityId');

        if (!$entityId) {
            $this->addFlash('warning', 'Signalement introuvable.');

            return null;
        }

        $report = $commentReportRepository->find($entityId);

        if (!$report instanceof CommentReport) {
            $this->addFlash('warning', 'Signalement introuvable.');

            return null;
        }

        return $report;
    }

    private function resolveReport(CommentReport $report, CommentReportStatus $status): void
    {
        $user = $this->getUser();

        $report
            ->setStatus($status)
            ->setResolvedAt(new \DateTimeImmutable())
            ->setResolvedBy($user instanceof User ? $user : null);
    }

    private function formatStatusBadge(CommentReport $report): string
    {
        return sprintf(
            '<span class="badge badge-%s">%s</span>',
            $report->getStatusBadgeClass(),
            $report->getStatusLabel()
        );
    }

    private function redirectToReportIndex(): RedirectResponse
    {
        return $this->redirectToRoute('admin_comment_report_index');
    }
}
