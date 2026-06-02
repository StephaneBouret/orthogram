<?php

namespace App\Controller\Admin;

use App\Entity\Invitation;
use App\Enum\InvitationType;
use App\Repository\InvitationRepository;
use App\Services\InvitationService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\RedirectResponse;

class InvitationCrudController extends AbstractCrudController
{
    public function __construct(private readonly InvitationService $invitationService) {}

    public static function getEntityFqcn(): string
    {
        return Invitation::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            // ->setEntityPermission('ROLE_ADMIN')
            ->setEntityLabelInSingular('Invitation')
            ->setEntityLabelInPlural('Invitations')
            ->setPageTitle(Crud::PAGE_INDEX, 'Invitations')
            ->setPageTitle(Crud::PAGE_NEW, 'Créer une invitation')
            ->setPageTitle(Crud::PAGE_EDIT, 'Modifier une invitation')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            EmailField::new('email', 'Email'),
            ChoiceField::new('type', 'Type d\'invitation')
                ->setChoices([
                    InvitationType::FREE_YEAR->label() => InvitationType::FREE_YEAR,
                    InvitationType::LIFETIME->label() => InvitationType::LIFETIME,
                ])
                ->renderAsBadges([
                    InvitationType::FREE_YEAR->value => 'info',
                    InvitationType::LIFETIME->value => 'success',
                ]),
            TextField::new('adminStatus', 'État')
                ->onlyOnIndex()
                ->formatValue(function ($value, Invitation $invitation): string {
                    $class = match (true) {
                        $invitation->isUsed() => 'success',
                        $invitation->isExpired() => 'danger',
                        $invitation->isSent() => 'info',
                        default => 'secondary',
                    };

                    return sprintf(
                        '<span class="badge badge-%s">%s</span>',
                        $class,
                        $value
                    );
                })
                ->renderAsHtml(),
            TextField::new('token', 'Token')
                ->hideOnForm(),
            DateTimeField::new('expiresAt', 'Expire le')
                ->setHelp('Si vide, l\'invitation expirera automatiquement dans 7 jours.')
                ->hideOnIndex(),
            DateTimeField::new('sentAt', 'Envoyé le')
                ->hideOnForm(),
            DateTimeField::new('usedAt', 'Utilisée le')
                ->hideOnForm(),
            DateTimeField::new('createdAt', 'Créée le')
                ->hideOnForm(),
            DateTimeField::new('updatedAt', 'Modifiée le')
                ->hideOnForm()
                ->hideOnIndex(),
            AssociationField::new('user', 'Utilisateur')
                ->hideOnForm()
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        $sendInvitation = Action::new('sendInvitation', 'Envoyer', 'fa fa-paper-plane')
            ->linkToCrudAction('sendInvitation')
            ->displayIf(static function (Invitation $invitation): bool {
                return !$invitation->isSent()
                    && !$invitation->isUsed()
                    && !$invitation->isExpired();
            })
            ->addCssClass('btn btn-primary');

        return $actions
            ->add(Crud::PAGE_INDEX, $sendInvitation)
            ->add(Crud::PAGE_DETAIL, $sendInvitation)

            ->update(
                Crud::PAGE_INDEX,
                Action::NEW,
                static fn(Action $action) => $action->setLabel('Créer une invitation')
            )

            ->update(
                Crud::PAGE_INDEX,
                Action::EDIT,
                static fn(Action $action) => $action
                    ->displayIf(static fn(Invitation $invitation): bool => !$invitation->isSent())
            )

            ->update(
                Crud::PAGE_DETAIL,
                Action::EDIT,
                static fn(Action $action) => $action
                    ->displayIf(static fn(Invitation $invitation): bool => !$invitation->isSent())
            );
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('email')
            ->add('type')
            ->add('sentAt')
            ->add('usedAt')
            ->add('expiresAt')
            ->add('createdAt');
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof Invitation) {
            parent::persistEntity($entityManager, $entityInstance);

            return;
        }

        try {
            $this->invitationService->assertCanCreateInvitation($entityInstance);

            $this->invitationService->initializeInvitation($entityInstance);

            parent::persistEntity($entityManager, $entityInstance);
        } catch (\RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof Invitation) {
            parent::updateEntity($entityManager, $entityInstance);

            return;
        }

        if ($entityInstance->isSent()) {
            $this->addFlash(
                'warning',
                'Cette invitation a déjà été envoyée et ne peut plus être modifiée.'
            );

            return;
        }

        try {
            $this->invitationService->assertCanCreateInvitation($entityInstance);
            parent::updateEntity($entityManager, $entityInstance);
        } catch (\RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }
    }

    #[AdminRoute(path: '/send-invitation', name: 'send_invitation')]
    public function sendInvitation(
        AdminContext $context,
        InvitationRepository $invitationRepository
    ): RedirectResponse {
        $entityId = $context->getRequest()->query->get('entityId');

        if (!$entityId) {
            $this->addFlash('warning', 'Invitation introuvable.');

            return $this->redirectToInvitationIndex();
        }

        $invitation = $invitationRepository->find($entityId);

        if (!$invitation instanceof Invitation) {
            $this->addFlash('warning', 'Invitation introuvable.');

            return $this->redirectToInvitationIndex();
        }

        if ($invitation->isSent()) {
            $this->addFlash('warning', 'Cette invitation a déjà été envoyée.');

            return $this->redirectToInvitationIndex();
        }

        if ($invitation->isUsed()) {
            $this->addFlash('warning', 'Cette invitation a déjà été utilisée.');

            return $this->redirectToInvitationIndex();
        }

        if ($invitation->isExpired()) {
            $this->addFlash('warning', 'Cette invitation a expiré.');

            return $this->redirectToInvitationIndex();
        }

        $this->invitationService->initializeInvitation($invitation);
        $this->invitationService->sendInvitation($invitation);

        $this->addFlash('success', sprintf(
            'Invitation envoyée à %s.',
            $invitation->getEmail()
        ));

        return $this->redirectToInvitationIndex();
    }

    private function redirectToInvitationIndex(): RedirectResponse
    {
        return $this->redirectToRoute('admin_invitation_index');
    }
}
