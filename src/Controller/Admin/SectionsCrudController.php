<?php

namespace App\Controller\Admin;

use App\Entity\Program;
use App\Entity\Sections;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class SectionsCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Sections::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityPermission('ROLE_ADMIN')
            ->setEntityLabelInSingular('Section')
            ->setEntityLabelInPlural('Sections')
            ->setPageTitle(Crud::PAGE_INDEX, 'Sections')
            ->setPageTitle(Crud::PAGE_DETAIL, 'Détail de la section')
            ->setPageTitle(Crud::PAGE_EDIT, 'Modifier la section')
            ->setDefaultSort(['id' => 'ASC'])
            ->setPaginatorPageSize(10);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = parent::configureActions($actions);
        return $actions;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('name', 'Nom de la section'),
            TextareaField::new('shortDescription', 'Description courte'),
            AssociationField::new('program', 'Programme de formation')
            ->setQueryBuilder(
                fn (QueryBuilder $queryBuilder) => $queryBuilder->getEntityManager()->getRepository(Program::class)->createQueryBuilder('p')->orderBy('p.name')
            )
            ->autocomplete(),
        ];
    }
}
