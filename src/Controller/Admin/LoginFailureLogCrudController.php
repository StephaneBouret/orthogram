<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\LoginFailureLog;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class LoginFailureLogCrudController extends AbstractCrudController
{
    use SecurityCrudFormattingTrait;

    public static function getEntityFqcn(): string
    {
        return LoginFailureLog::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityPermission('ROLE_ADMIN')
            ->setEntityLabelInSingular('Tentative de connexion')
            ->setEntityLabelInPlural('Tentatives de connexion')
            ->setPageTitle(Crud::PAGE_INDEX, 'Tentatives de connexion échouées')
            ->setPageTitle(Crud::PAGE_DETAIL, fn (LoginFailureLog $log): string => sprintf('Tentative #%d', $log->getId()))
            ->setDefaultSort(['occurredAt' => 'DESC'])
            ->setSearchFields(['usernameAttempted', 'ipAddress'])
            ->setPaginatorPageSize(50)
            ->setTimezone(self::APP_TIMEZONE)
            ->setDefaultRowAction(Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            DateTimeField::new('occurredAt', 'Date')
                ->setTimezone(self::APP_TIMEZONE),
            TextField::new('usernameAttempted', 'Identifiant tenté'),
            TextField::new('user', 'Statut')
                ->formatValue(fn (mixed $value, LoginFailureLog $log): string => $this->formatUserStatusBadge($log))
                ->renderAsHtml()
                ->setSortable(false),
            AssociationField::new('user', 'Utilisateur')
                ->formatValue(fn (mixed $value, LoginFailureLog $log): string => $log->getUser()?->getFullname() ?? 'Utilisateur inconnu'),
            TextField::new('ipAddress', 'Adresse IP')
                ->formatValue(fn (mixed $value): string => $this->formatMonospaceValue($value))
                ->renderAsHtml(),
            TextareaField::new('userAgent', 'User-Agent')
                ->formatValue(fn (mixed $value): string => Crud::PAGE_INDEX === $pageName
                    ? $this->formatUserAgentPreview($value)
                    : $this->formatNullableValue($value))
                ->renderAsHtml()
                ->setSortable(false),
        ];
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('occurredAt')
            ->add('user')
            ->add('ipAddress');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    private function formatUserStatusBadge(LoginFailureLog $log): string
    {
        if (null === $log->getUser()) {
            return '<span class="badge badge-danger"><i class="fa fa-circle-xmark"></i> Utilisateur inconnu</span>';
        }

        return '<span class="badge badge-success"><i class="fa fa-circle-check"></i> Utilisateur trouvé</span>';
    }

    private function formatUserAgentPreview(mixed $value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        $userAgent = (string) $value;
        $preview = mb_strlen($userAgent) > 80 ? mb_substr($userAgent, 0, 77).'...' : $userAgent;

        return sprintf(
            '<span title="%s">%s</span>',
            $this->escapeHtml($userAgent),
            $this->escapeHtml($preview)
        );
    }

    private function formatNullableValue(mixed $value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        return nl2br($this->escapeHtml((string) $value));
    }
}
