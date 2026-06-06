<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\AvatarFormType;
use App\Services\AvatarService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use libphonenumber\PhoneNumberFormat;
use Misd\PhoneNumberBundle\Form\Type\PhoneNumberType;
use Misd\PhoneNumberBundle\Templating\Helper\PhoneNumberHelper;

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
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
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
}
