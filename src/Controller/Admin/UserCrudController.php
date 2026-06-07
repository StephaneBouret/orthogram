<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\UserAccountStatus;
use App\Form\AvatarFormType;
use App\Services\AvatarService;
use App\Repository\UserRepository;
use App\Services\UserAdminActionService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use libphonenumber\PhoneNumberFormat;
use Misd\PhoneNumberBundle\Form\Type\PhoneNumberType;
use Misd\PhoneNumberBundle\Templating\Helper\PhoneNumberHelper;
use Symfony\Component\HttpFoundation\RedirectResponse;

class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly PhoneNumberHelper $phoneNumberHelper,
        private readonly AvatarService $avatarService,
    ) {}

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityPermission('ROLE_ADMIN')
            ->setEntityLabelInSingular('Utilisateur')
            ->setEntityLabelInPlural('Utilisateurs')
            ->setPageTitle(Crud::PAGE_INDEX, 'Utilisateurs')
            ->setPageTitle(Crud::PAGE_DETAIL, fn (User $user): string => $user->getFullname())
            ->setPageTitle(Crud::PAGE_EDIT, fn (User $user): string => sprintf('Modifier %s', $user->getFullname()))
            ->setDefaultSort(['id' => 'ASC'])
            ->setPaginatorPageSize(10);
    }

    public function configureActions(Actions $actions): Actions
    {
        $suspendUser = Action::new('suspendUser', 'Suspendre', 'fa fa-pause')
            ->linkToCrudAction('suspendUser')
            ->displayIf(fn (User $user): bool => $user->getAccountStatus() === UserAccountStatus::ACTIVE
                && $user->getId() !== $this->getUser()?->getId())
            ->addCssClass('btn btn-warning');

        $reactivateUser = Action::new('reactivateUser', 'Réactiver', 'fa fa-play')
            ->linkToCrudAction('reactivateUser')
            ->displayIf(static function (User $user): bool {
                return $user->getAccountStatus() === UserAccountStatus::SUSPENDED;
            })
            ->addCssClass('btn btn-success');

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, $suspendUser)
            ->add(Crud::PAGE_EDIT, $reactivateUser)
            ->disable(Action::NEW);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            ImageField::new('avatar.imageName', 'Avatar')
                ->setBasePath('/images/avatars')
                ->hideOnForm(),
            FormField::addFieldset('Détails de l\'utilisateur'),
            TextField::new('firstname', 'Prénom')
                ->setColumns(6),
            TextField::new('lastname', 'Nom')
                ->setColumns(6),
            EmailField::new('email', 'Email'),
            TextField::new('accountStatusLabel', 'Statut')
                ->formatValue(fn ($value, User $user): string => $this->formatAccountStatusBadge($user))
                ->renderAsHtml()
                ->setSortable(false)
                ->hideOnForm(),
            TextField::new('accountStatusLabel', 'Statut')
                ->setFormTypeOption('disabled', true)
                ->onlyOnForms(),
            TextField::new('address', 'Adresse')
                ->setColumns(6)
                ->hideOnIndex(),
            TextField::new('postalCode', 'Code postal')
                ->setColumns(6)
                ->hideOnIndex(),
            TextField::new('city', 'Ville')
                ->setColumns(6)
                ->hideOnIndex(),
            TextField::new('phone', 'Téléphone')
                ->setFormType(PhoneNumberType::class)
                ->setFormTypeOptions([
                    'default_region' => 'FR',
                    'format' => PhoneNumberFormat::NATIONAL,
                    'number_type' => PhoneNumberType::NUMBER_TYPE_TEL,
                    'attr' => ['placeholder' => 'Téléphone de l\'utilisateur'],
                ])
                ->setColumns(6)
                ->onlyOnForms(),
            TextField::new('phone', 'Téléphone')
                ->formatValue(function ($value, ?User $user): string {
                    $phone = $user?->getPhone();

                    return null === $phone ? '' : $this->phoneNumberHelper->format($phone, PhoneNumberFormat::NATIONAL);
                })
                ->hideOnForm(),
            TextField::new('avatar', 'Avatar')
                ->setFormType(AvatarFormType::class)
                ->onlyOnForms(),
            FormField::addFieldset('Rôles de l\'utilisateur'),
            ChoiceField::new('roles', 'Rôles')
                ->setChoices([
                    'Administrateur' => 'ROLE_ADMIN',
                    'Utilisateur' => 'ROLE_USER',
                ])
                ->allowMultipleChoices()
                ->renderExpanded()
                ->setFormTypeOption('choice_attr', static fn (mixed $choice, string $key, mixed $value): array => 'ROLE_USER' === $value ? ['disabled' => true] : [])
                ->hideOnIndex(),
            ChoiceField::new('roles', 'Rôles')
                ->setChoices([
                    'Administrateur' => 'ROLE_ADMIN',
                    'Utilisateur' => 'ROLE_USER',
                ])
                ->renderAsBadges()
                ->hideOnForm(),
        ];
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('email')
            ->add(ChoiceFilter::new('accountStatus', 'Statut')->setChoices([
                'Actif' => UserAccountStatus::ACTIVE->value,
                'Inactif' => UserAccountStatus::INACTIVE->value,
                'Suspendu' => UserAccountStatus::SUSPENDED->value,
                'En attente de vérification' => UserAccountStatus::PENDING_VERIFICATION->value,
                'Supprimé' => UserAccountStatus::DELETED->value,
            ]))
            ->add('roles');
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof User) {
            return;
        }

        $this->normalizeUser($entityInstance);

        parent::updateEntity($entityManager, $entityInstance);

        $avatar = $entityInstance->getAvatar();

        if ($avatar !== null) {
            $this->avatarService->convertStoredImageToWebp($avatar);
        }
    }

    #[AdminRoute(path: '/suspend-user', name: 'suspend_user')]
    public function suspendUser(
        AdminContext $context,
        UserRepository $userRepository,
        UserAdminActionService $userAdminActionService
    ): RedirectResponse {
        $user = $this->getUserFromContext($context, $userRepository);

        if (!$user instanceof User) {
            return $this->redirectToUserIndex();
        }

        if ($user->getId() === $this->getUser()?->getId()) {
            $this->addFlash('warning', 'Vous ne pouvez pas suspendre votre propre compte administrateur.');

            return $this->redirectToUserIndex();
        }

        try {
            $emailSent = $userAdminActionService->suspend($user);
        } catch (\RuntimeException $e) {
            $this->addFlash('warning', $e->getMessage());

            return $this->redirectToUserIndex();
        }

        $this->addFlash('success', 'Le compte utilisateur a été suspendu.');
        $this->addNotificationFlash($emailSent);

        return $this->redirectToUserIndex();
    }

    #[AdminRoute(path: '/reactivate-user', name: 'reactivate_user')]
    public function reactivateUser(
        AdminContext $context,
        UserRepository $userRepository,
        UserAdminActionService $userAdminActionService
    ): RedirectResponse {
        $user = $this->getUserFromContext($context, $userRepository);

        if (!$user instanceof User) {
            return $this->redirectToUserIndex();
        }

        try {
            $emailSent = $userAdminActionService->reactivate($user);
        } catch (\RuntimeException $e) {
            $this->addFlash('warning', $e->getMessage());

            return $this->redirectToUserIndex();
        }

        $this->addFlash('success', 'Le compte utilisateur a été réactivé.');
        $this->addNotificationFlash($emailSent);

        return $this->redirectToUserIndex();
    }

    private function getUserFromContext(AdminContext $context, UserRepository $userRepository): ?User
    {
        $entityId = $context->getRequest()->query->get('entityId');

        if (!$entityId) {
            $this->addFlash('warning', 'Utilisateur introuvable.');

            return null;
        }

        $user = $userRepository->find($entityId);

        if (!$user instanceof User) {
            $this->addFlash('warning', 'Utilisateur introuvable.');

            return null;
        }

        return $user;
    }

    private function normalizeUser(User $user): void
    {
        $roles = $user->getRoles();
        if (!in_array('ROLE_USER', $roles, true)) {
            $roles[] = 'ROLE_USER';
        }

        $user
            ->setRoles($roles)
            ->setFirstname($this->normalizeFirstname((string) $user->getFirstname()))
            ->setLastname($this->normalizeLastname((string) $user->getLastname()));
    }

    private function normalizeFirstname(string $firstname): string
    {
        $firstname = mb_strtolower(trim($firstname), 'UTF-8');

        return mb_convert_case($firstname, MB_CASE_TITLE, 'UTF-8');
    }

    private function normalizeLastname(string $lastname): string
    {
        return mb_strtoupper(trim($lastname), 'UTF-8');
    }

    private function formatAccountStatusBadge(User $user): string
    {
        $status = $user->getAccountStatus();
        $class = match ($status) {
            UserAccountStatus::ACTIVE => 'success',
            UserAccountStatus::INACTIVE => 'secondary',
            UserAccountStatus::SUSPENDED => 'danger',
            UserAccountStatus::PENDING_VERIFICATION => 'warning',
            UserAccountStatus::DELETED => 'dark',
        };

        return sprintf(
            '<span class="badge badge-%s">%s</span>',
            $class,
            mb_strtoupper($status->label())
        );
    }

    private function redirectToUserIndex(): RedirectResponse
    {
        return $this->redirectToRoute('admin_user_index');
    }

    private function addNotificationFlash(bool $emailSent): void
    {
        if ($emailSent) {
            $this->addFlash('success', 'Un email automatique a été envoyé à l\'utilisateur.');

            return;
        }

        $this->addFlash('warning', 'Action effectuée, mais l\'email automatique n\'a pas pu être envoyé.');
    }
}
