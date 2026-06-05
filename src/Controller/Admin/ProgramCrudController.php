<?php

namespace App\Controller\Admin;

use App\Entity\Program;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Vich\UploaderBundle\Form\Type\VichImageType;

class ProgramCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Program::class;
    }

    public function configureAssets(Assets $assets): Assets
    {
        return $assets->addAssetMapperEntry('admin_check_name');
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityPermission('ROLE_ADMIN')
            ->setEntityLabelInSingular('Programme')
            ->setEntityLabelInPlural('Programmes')
            ->setPageTitle(Crud::PAGE_INDEX, 'Programmes')
            ->setPageTitle(Crud::PAGE_DETAIL, 'Détail du programme')
            ->setPageTitle(Crud::PAGE_EDIT, 'Modifier le programme')
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
            TextField::new('name', 'Nom du programme')
                ->setHelp('Entre 3 et 30 caractères')
                ->addCssClass('char-count'),
            TextareaField::new('description', 'Description du programme')
                ->setHelp('Deux lignes maximum')
                ->hideOnIndex(),
            MoneyField::new('price', 'Prix du programme')->setCurrency('EUR'),
            TextField::new('imageFile', 'Fichier image :')
                ->setFormType(VichImageType::class)
                ->setTranslationParameters(['form.label.delete' => 'Supprimer l\'image'])
                ->hideOnIndex(),
            ImageField::new('imageName', 'Image')
                ->setBasePath('/images/programs')
                ->onlyOnIndex(),
            FormField::addFieldset('Pourquoi cette formation ?')->renderCollapsed(),
            TextField::new('whyThisTrainingTitle', 'Titre partie "Pourquoi cette formation ?"')->hideOnIndex(),
            TextEditorField::new('whyThisTrainingContent', 'Contenu partie "Pourquoi cette formation ?"')
                ->hideOnIndex()
                ->setHelp('Texte avec mise en forme HTML autorisée'),
            FormField::addFieldset('Structure du programme')->renderCollapsed(),
            TextField::new('structuredProgramTitle', 'Titre partie "Deuxième bloc"')->hideOnIndex(),
            TextEditorField::new('structuredProgramContent', 'Contenu partie "Deuxième bloc"')
                ->hideOnIndex()
                ->setHelp('Texte avec mise en forme HTML autorisée'),
            FormField::addFieldset('Parcours détaillé du programme')->renderCollapsed(),
            CollectionField::new('programHighlights', 'Blocs détaillés du programme')
                ->useEntryCrudForm(ProgramHighlightCrudController::class)
                ->setEntryIsComplex(true)
                ->setFormTypeOption('by_reference', false)
                ->hideOnIndex()
                ->setHelp('Ajoute les blocs longs : bases, accords, pièges fréquents, automatismes...'),
            FormField::addFieldset("Ce que l'apprenant va maîtriser")->renderCollapsed(),
            CollectionField::new('programDetails', 'Objectifs pédagogiques')
                ->useEntryCrudForm(ProgramDetailCrudController::class)
                ->setEntryIsComplex(true)
                ->setFormTypeOption('by_reference', false)
                ->hideOnIndex()
                ->setHelp('Ajoute les bénéfices courts affichés dans la section "Ce que vous allez apprendre".'),
        ];
    }
}
