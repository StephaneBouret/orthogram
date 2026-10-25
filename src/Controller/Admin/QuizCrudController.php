<?php

namespace App\Controller\Admin;

use App\Entity\Quiz;
use App\Services\QuizCompositionSummary;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class QuizCrudController extends AbstractQuizEditorialCrudController
{
    public static function getEntityFqcn(): string
    {
        return Quiz::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityPermission('ROLE_ADMIN')
            ->setEntityLabelInSingular('Quiz')
            ->setEntityLabelInPlural('Quiz')
            ->overrideTemplate('crud/edit', 'admin/quiz/edit.html.twig')
            ->setDefaultSort(['title' => 'ASC', 'id' => 'ASC']);
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_EDIT === $responseParameters->get('pageName')) {
            $quiz = $responseParameters->get('entity')->getInstance();
            $responseParameters->set('quizComposition', (new QuizCompositionSummary())->build($quiz));
        }

        return $responseParameters;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title', 'Titre')->setFormTypeOption('empty_data', '')
                ->setHelp('Enregistrez le quiz, puis ajoutez ses questions dans « Questions des quiz ». Un quiz peut être préparé sans question.'),
            ChoiceField::new('renewalEnabled', 'Mode du quiz')->onlyOnForms()->renderAsNativeWidget()
                ->setChoices(['Fixe' => false, 'Avec renouvellement' => true])->setRequired(true)
                ->setFormTypeOption('placeholder', false)
                ->setHelp('Activez explicitement le renouvellement une fois les questions prêtes. Le premier passage reste fixe ; les suivants sont renouvelés selon les possibilités de la réserve.'),
            TextField::new('modeLabel', 'Mode du quiz')->hideOnForm()->setSortable(false),
            IntegerField::new('initialQuestionCount', 'Questions présentées (initiales)')->hideOnForm()->setSortable(false),
            IntegerField::new('reserveQuestionCount', 'Questions de réserve')->hideOnForm()->setSortable(false),
            IntegerField::new('errorQuestionQuota', 'Phrases contenant une erreur')->setRequired(false)
                ->onlyOnForms()
                ->setFormTypeOption('attr', ['min' => 0])->setFormTypeOption('empty_data', null)
                ->setHelp('Ce quota s’applique aussi en mode fixe. Laissez vide pendant la préparation ou pour ne pas imposer d’équilibre. Zéro signifie : toutes les phrases présentées doivent être correctes. Renseignez les catégories du jeu initial avant de fixer le quota ; en mode renouvellement, celles des réserves sont également requises.'),
            TextField::new('balanceLabel', 'Équilibre global')->hideOnForm()->setSortable(false),
            Field::new('composition', 'Composition actuelle')->setVirtual(true)->onlyOnDetail()
                ->formatValue(static fn ($value, Quiz $quiz) => (new QuizCompositionSummary())->build($quiz))
                ->setTemplatePath('admin/quiz/composition.html.twig'),
            AssociationField::new('questions', 'Questions')->onlyOnDetail(),
            AssociationField::new('courses', 'Cours associés')->onlyOnDetail(),
        ];
    }
}
