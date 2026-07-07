<?php

namespace App\Controller\Admin;

use App\Entity\Comment;
use App\Entity\User;
use App\Repository\CommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use Symfony\Component\HttpFoundation\RedirectResponse;

class CommentCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Comment::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityPermission('ROLE_ADMIN')
            ->setEntityLabelInSingular('Commentaire')
            ->setEntityLabelInPlural('Commentaires')
            ->setPageTitle(Crud::PAGE_INDEX, 'Commentaires')
            ->setPageTitle(Crud::PAGE_EDIT, fn (Comment $comment) => sprintf('Modifier le commentaire #%d', $comment->getId()))
            ->setDefaultSort(['createdAt' => 'DESC', 'id' => 'DESC'])
            ->setPaginatorPageSize(20);
    }

    public function configureActions(Actions $actions): Actions
    {
        $hideComment = Action::new('hideComment', 'Masquer', 'fa fa-eye-slash')
            ->linkToCrudAction('hideComment')
            ->displayIf(static fn (Comment $comment): bool => !$comment->isHidden())
            ->addCssClass('btn btn-warning');

        $showComment = Action::new('showComment', 'Réafficher', 'fa fa-eye')
            ->linkToCrudAction('showComment')
            ->displayIf(static fn (Comment $comment): bool => $comment->isHidden())
            ->addCssClass('btn btn-success');

        return $actions
            ->remove(Crud::PAGE_INDEX, Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $hideComment)
            ->add(Crud::PAGE_INDEX, $showComment)
            ->add(Crud::PAGE_DETAIL, $hideComment)
            ->add(Crud::PAGE_DETAIL, $showComment);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('course', 'Cours')->onlyOnIndex(),
            AssociationField::new('user', 'Utilisateur')
                ->formatValue(fn (mixed $value, Comment $comment) => $comment->getUser()?->getFullname() ?? 'Utilisateur inconnu'),
            TextareaField::new('content', 'Commentaire'),
            BooleanField::new('isHidden', 'Masqué')
                ->renderAsSwitch(false)
                ->hideOnForm(),
            DateTimeField::new('hiddenAt', 'Masqué le')
                ->hideOnForm()
                ->hideOnIndex(),
            AssociationField::new('hiddenBy', 'Masqué par')
                ->hideOnForm()
                ->hideOnIndex(),
            AssociationField::new('parent', 'Réponse à')->onlyOnForms(),
            DateTimeField::new('createdAt', 'Créé le')->onlyOnIndex(),
            DateTimeField::new('updatedAt', 'Modifié le')->onlyOnIndex(),
        ];
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('course')
            ->add('user')
            ->add('isHidden')
            ->add('createdAt');
    }

    #[AdminRoute(path: '/hide-comment', name: 'hide_comment')]
    public function hideComment(
        AdminContext $context,
        CommentRepository $commentRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $comment = $this->getCommentFromContext($context, $commentRepository);

        if (!$comment instanceof Comment) {
            return $this->redirectToCommentIndex();
        }

        $user = $this->getUser();
        $comment->hide($user instanceof User ? $user : null);
        $entityManager->flush();

        $this->addFlash('success', 'Le commentaire est maintenant masqué.');

        return $this->redirectToCommentIndex();
    }

    #[AdminRoute(path: '/show-comment', name: 'show_comment')]
    public function showComment(
        AdminContext $context,
        CommentRepository $commentRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $comment = $this->getCommentFromContext($context, $commentRepository);

        if (!$comment instanceof Comment) {
            return $this->redirectToCommentIndex();
        }

        $comment->show();
        $entityManager->flush();

        $this->addFlash('success', 'Le commentaire est de nouveau visible.');

        return $this->redirectToCommentIndex();
    }

    private function getCommentFromContext(AdminContext $context, CommentRepository $commentRepository): ?Comment
    {
        $entityId = $context->getRequest()->query->get('entityId');

        if (!$entityId) {
            $this->addFlash('warning', 'Commentaire introuvable.');

            return null;
        }

        $comment = $commentRepository->find($entityId);

        if (!$comment instanceof Comment) {
            $this->addFlash('warning', 'Commentaire introuvable.');

            return null;
        }

        return $comment;
    }

    private function redirectToCommentIndex(): RedirectResponse
    {
        return $this->redirectToRoute('admin_comment_index');
    }
}
