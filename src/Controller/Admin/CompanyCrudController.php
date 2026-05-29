<?php

namespace App\Controller\Admin;

use App\Entity\Company;
use App\Enum\CompanyType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use libphonenumber\PhoneNumberFormat;
use Misd\PhoneNumberBundle\Form\Type\PhoneNumberType;
use Misd\PhoneNumberBundle\Templating\Helper\PhoneNumberHelper;

class CompanyCrudController extends AbstractCrudController
{
    public function __construct(protected PhoneNumberHelper $phoneNumberHelper) {}

    public static function getEntityFqcn(): string
    {
        return Company::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            // ->setEntityPermission('ROLE_ADMIN')
            ->setEntityLabelInSingular('Entreprise')
            ->setEntityLabelInPlural('Entreprises')
            ->setPageTitle(Crud::PAGE_INDEX, 'Entreprise')
            ->setPageTitle(Crud::PAGE_DETAIL, 'Détail de l\'entreprise')
            ->setPageTitle(Crud::PAGE_EDIT, 'Modifier l\'entreprise')
            ->setDefaultSort(['id' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('name', 'Raison sociale :'),
            ChoiceField::new('type', 'Type :')
                ->setChoices(CompanyType::choices())
                ->renderExpanded()
                ->onlyOnForms(),
            ChoiceField::new('type', 'Type :')
                ->setChoices(CompanyType::choices())
                ->formatValue(static fn ($value, ?Company $entity): string => $entity?->getType()?->label() ?? '')
                ->hideOnForm(),
            TextField::new('address', 'Adresse :')
                ->setFormTypeOptions(['attr' => ['placeholder' => 'Adresse de l\'entreprise']])
                ->setColumns(6)
                ->hideOnIndex(),
            TextField::new('postalCode', 'Code postal :')
                ->setFormTypeOptions(['attr' => ['placeholder' => 'Code postal de l\'entreprise']])
                ->setColumns(6)
                ->hideOnIndex(),
            TextField::new('city', 'Ville :')
                ->setFormTypeOptions(['attr' => ['placeholder' => 'Ville de l\'entreprise']])
                ->setColumns(6)
                ->hideOnIndex(),
            TextField::new('phone', 'Téléphone')
                ->setFormType(PhoneNumberType::class)
                ->setFormTypeOptions([
                    'default_region' => 'FR',
                    'format' => PhoneNumberFormat::NATIONAL,
                    'attr' => ['placeholder' => 'Téléphone de l\'entreprise']
                ])
                ->setColumns(6)
                ->onlyOnForms(),
            TextField::new('phone', 'Téléphone')
                ->formatValue(function ($value, $entity) {
                    $value = $entity->getPhone();

                    return null === $value ? '' : $this->phoneNumberHelper->format($value, PhoneNumberFormat::NATIONAL);
                })
                ->onlyOnIndex(),
            EmailField::new('email', 'Email de l\'entreprise :')
                ->setFormTypeOptions(['attr' => ['placeholder' => 'Email de l\'entreprise']]),
            TextField::new('siren', 'SIREN / SIRET :')
                ->setFormTypeOptions(['attr' => ['placeholder' => 'SIREN ou SIRET de l\'entreprise']])
                ->setColumns(6)
                ->hideOnIndex(),
            TextField::new('manager', 'Prénom et Nom du dirigeant')
                ->hideOnIndex()
                ->setHelp('Merci d\'indiquer le prénom et le nom du dirigeant'),
            UrlField::new('url', 'Site web de l\'entreprise')
                ->hideOnIndex(),
            TextField::new('websiteCreator', 'Concepteur du site')
                ->hideOnIndex()
                ->setHelp('Merci d\'indiquer le prénom et le nom du concepteur du site'),
        ];
    }
}
