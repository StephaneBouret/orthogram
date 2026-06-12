<?php

namespace App\Controller\Admin;

use App\Entity\Courses;
use App\Entity\Sections;
use App\Enum\CourseContentType;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Vich\UploaderBundle\Form\Type\VichFileType;

class CoursesCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Courses::class;
    }

    public function configureAssets(Assets $assets): Assets
    {
        return $assets->addAssetMapperEntry('admin_courses');
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityPermission('ROLE_ADMIN')
            ->setEntityLabelInSingular('Cours')
            ->setEntityLabelInPlural('Cours')
            ->setPageTitle(Crud::PAGE_INDEX, 'Cours')
            ->setPageTitle(Crud::PAGE_NEW, 'Créer un cours')
            ->setPageTitle(Crud::PAGE_DETAIL, fn (Courses $course) => (string) $course)
            ->setPageTitle(Crud::PAGE_EDIT, fn (Courses $course) => sprintf('Modifier %s', (string) $course))
            ->setDefaultSort(['position' => 'ASC', 'id' => 'ASC'])
            ->setPaginatorPageSize(20);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('name', 'Nom du cours'),
            TextField::new('slug', 'Slug')->onlyOnIndex(),
            ChoiceField::new('contentType', 'Type de contenu')
                ->setChoices(CourseContentType::cases())
                ->setFormTypeOption('choice_label', fn (CourseContentType $type) => $type->label())
                ->renderAsBadges([
                    CourseContentType::Twig->value => 'primary',
                    CourseContentType::Audio->value => 'info',
                    CourseContentType::Video->value => 'warning',
                    CourseContentType::Quiz->value => 'success',
                    CourseContentType::Link->value => 'secondary',
                ])
                ->setRequired(true),
            IntegerField::new('position', 'Ordre d’affichage')
                ->setHelp('0 = premier cours de la section, puis 1, 2, 3...'),
            AssociationField::new('section', 'Programme / section')
                ->setQueryBuilder(
                    fn (QueryBuilder $queryBuilder) => $queryBuilder
                        ->leftJoin('entity.program', 'p')
                        ->addSelect('p')
                        ->orderBy('p.name', 'ASC')
                        ->addOrderBy('entity.name', 'ASC')
                )
                ->setFormTypeOption('choice_label', fn (Sections $section) => $section->getAdminLabel())
                ->formatValue(fn (?Sections $section) => $section?->getAdminLabel() ?? ''),
            TextareaField::new('shortDescription', 'Description courte')->hideOnIndex(),

            FormField::addFieldset('Fichiers')->hideOnIndex(),
            TextField::new('partialFile', 'Template Twig')
                ->setFormType(VichFileType::class)
                ->setFormTypeOption('download_label', fn (Courses $course) => $course->getPartialFileName())
                ->setFormTypeOption('delete_label', 'Supprimer le fichier')
                ->setTranslationParameters(['form.label.delete' => 'Supprimer le fichier'])
                ->addCssClass('field-partialFile')
                ->hideOnIndex(),
            TextField::new('partialFileName', 'Fichier Twig')->onlyOnIndex(),
            TextField::new('audioFile', 'Fichier audio')
                ->setFormType(VichFileType::class)
                ->setFormTypeOption('download_label', fn (Courses $course) => $course->getAudioFileName())
                ->setFormTypeOption('delete_label', 'Supprimer le fichier')
                ->setTranslationParameters(['form.label.delete' => 'Supprimer le fichier'])
                ->addCssClass('field-audioFile')
                ->hideOnIndex(),
            TextField::new('audioFileName', 'Audio')->onlyOnIndex(),
            TextField::new('videoFile', 'Fichier vidéo')
                ->setFormType(VichFileType::class)
                ->setFormTypeOption('download_label', fn (Courses $course) => $course->getVideoName())
                ->setFormTypeOption('delete_label', 'Supprimer le fichier')
                ->setTranslationParameters(['form.label.delete' => 'Supprimer le fichier'])
                ->addCssClass('field-videoFile')
                ->hideOnIndex(),
            TextField::new('videoName', 'Vidéo')->onlyOnIndex(),

            FormField::addFieldset('Dictée')->addCssClass('field-correctionText')->hideOnIndex(),
            TextareaField::new('correctionText', 'Correction')
                ->addCssClass('field-correctionText')
                ->hideOnIndex(),
        ];
    }
}
