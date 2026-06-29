<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\LoginFailureAlert;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class LoginFailureAlertCrudController extends AbstractCrudController
{
    use SecurityCrudFormattingTrait;

    public static function getEntityFqcn(): string
    {
        return LoginFailureAlert::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityPermission('ROLE_ADMIN')
            ->setEntityLabelInSingular('Alerte de sécurité')
            ->setEntityLabelInPlural('Alertes de sécurité')
            ->setPageTitle(Crud::PAGE_INDEX, 'Alertes de sécurité')
            ->setPageTitle(Crud::PAGE_DETAIL, fn (LoginFailureAlert $alert): string => sprintf('Alerte #%d', $alert->getId()))
            ->setDefaultSort(['sentAt' => 'DESC'])
            ->setSearchFields(['user.email', 'user.firstname', 'user.lastname'])
            ->setPaginatorPageSize(50)
            ->setTimezone(self::APP_TIMEZONE)
            ->setDefaultRowAction(Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            DateTimeField::new('sentAt', 'Envoyée le')
                ->setTimezone(self::APP_TIMEZONE),
            AssociationField::new('user', 'Utilisateur')
                ->formatValue(fn (mixed $value, LoginFailureAlert $alert): string => $alert->getUser()->getFullname()),
            IntegerField::new('failureCount', 'Échecs constatés'),
            TextField::new('ipAddress', 'Adresse IP')
                ->formatValue(fn (mixed $value): string => $this->formatMonospaceValue($value))
                ->renderAsHtml(),
        ];
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('sentAt')
            ->add('user');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }
}
