<?php

namespace App\Controller\Admin;

use App\Entity\QuizQuestion;
use App\Form\QuizAnswersType;
use App\Form\QuizAnswerType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class QuizQuestionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return QuizQuestion::class;
    }

    public function configureAssets(Assets $assets): Assets
    {
        return $assets->addAssetMapperEntry('admin_quiz_answers');
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityPermission('ROLE_ADMIN')
            ->setEntityLabelInSingular('Question de quiz')
            ->setEntityLabelInPlural('Questions des quiz')
            ->setDefaultSort(['position' => 'ASC', 'id' => 'ASC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('quiz');
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('quiz', 'Quiz')->setRequired(true),
            TextField::new('title', 'Consigne / titre')->setFormTypeOption('empty_data', ''),
            TextareaField::new('text', 'Phrase')->setRequired(false)->hideOnIndex(),
            TextareaField::new('explanation', 'Explication')->setRequired(false)
                ->setFormTypeOption('empty_data', '')->hideOnIndex(),
            in_array($pageName, [Crud::PAGE_NEW, Crud::PAGE_EDIT], true)
                ? ChoiceField::new('multiple', 'Mode de réponse')
                ->setChoices([
                    'Une seule réponse' => false,
                    'Plusieurs réponses' => true,
                ])
                ->setRequired(true)
                : BooleanField::new('multiple', 'Plusieurs réponses')
                ->renderAsSwitch(false),
            TextField::new('theme', 'Thème')->setRequired(false),
            IntegerField::new('position', 'Position')->setFormTypeOption('attr', ['min' => 0])
                ->setHelp('Ordre croissant dans le quiz. À position égale, l’identifiant départage les questions.'),
            CollectionField::new('answers', 'Propositions')
                ->setFormType(QuizAnswersType::class)
                ->setEntryType(QuizAnswerType::class)
                ->setEntryIsComplex()
                ->renderExpanded()
                ->allowAdd()->allowDelete()
                ->setFormTypeOption('by_reference', false)
                ->setFormTypeOption('error_bubbling', false)
                ->setFormTypeOption('delete_empty', false)
                ->setFormTypeOption('row_attr', ['data-quiz-answer-order' => ''])
                ->setHelp('Au moins deux propositions. Une bonne réponse en mode unique ; au moins une en mode multiple. Utilisez Monter et Descendre pour choisir l’ordre.')
                ->hideOnIndex(),
        ];
    }
}
