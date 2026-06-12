<?php

namespace App\Controller\Admin;

use App\Entity\Courses;
use App\Entity\Sections;
use App\Enum\CourseContentType;
use App\Repository\CoursesRepository;
use App\Services\Courses\CourseDurationEstimator;
use App\Services\Courses\CourseFileService;
use Doctrine\ORM\EntityManagerInterface;
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
    public function __construct(
        private readonly CourseDurationEstimator $courseDurationEstimator,
        private readonly CourseFileService $courseFileService,
        private readonly CoursesRepository $coursesRepository,
    ) {}

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
                ->setHelp($pageName === Crud::PAGE_NEW
                    ? 'Laisser 0 pour ajouter automatiquement le cours en fin de section.'
                    : 'Changer la position réordonne automatiquement les autres cours de la section.'),
            IntegerField::new('durationMinutes', 'Durée estimée (min)')
                ->setHelp('Laisser vide pour estimer automatiquement la durée d’un template Twig.'),
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
                ->setHelp('Utilisé pour les contenus Twig et Lien.')
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

    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->setPositionIfMissing($entityInstance);
        $this->reorderCourse($entityInstance);
        $this->estimateDurationIfMissing($entityInstance);

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $previousSection = $this->getPreviousSection($entityManager, $entityInstance);

        $this->reorderCourse($entityInstance, $previousSection);
        $this->estimateDurationIfMissing($entityInstance);

        parent::updateEntity($entityManager, $entityInstance);
    }

    private function estimateDurationIfMissing(object $entityInstance): void
    {
        if (!$entityInstance instanceof Courses) {
            return;
        }

        if ($entityInstance->getDurationMinutes() !== null) {
            return;
        }

        if (!in_array($entityInstance->getContentType(), [CourseContentType::Twig, CourseContentType::Link], true)) {
            return;
        }

        $content = $this->getTwigContent($entityInstance);

        if ($content === null || trim($content) === '') {
            return;
        }

        $entityInstance->setDurationMinutes($this->courseDurationEstimator->estimateReadingDuration($content));
    }

    private function setPositionIfMissing(object $entityInstance): void
    {
        if (!$entityInstance instanceof Courses) {
            return;
        }

        $section = $entityInstance->getSection();

        if ($section === null || $entityInstance->getPosition() !== 0) {
            return;
        }

        $entityInstance->setPosition($this->coursesRepository->getNextPositionForSection($section));
    }

    private function reorderCourse(object $entityInstance, ?Sections $previousSection = null): void
    {
        if (!$entityInstance instanceof Courses) {
            return;
        }

        $section = $entityInstance->getSection();

        if ($section === null) {
            return;
        }

        if ($previousSection !== null && !$this->isSameSection($previousSection, $section)) {
            $this->reindexSection($previousSection, $entityInstance);
        }

        $courses = $this->coursesRepository->findOrderedBySectionExcluding($section, $entityInstance);
        $targetPosition = max(0, min($entityInstance->getPosition() ?? 0, count($courses)));

        array_splice($courses, $targetPosition, 0, [$entityInstance]);

        foreach ($courses as $position => $course) {
            $course->setPosition($position);
        }
    }

    private function reindexSection(Sections $section, Courses $excludedCourse): void
    {
        foreach ($this->coursesRepository->findOrderedBySectionExcluding($section, $excludedCourse) as $position => $course) {
            $course->setPosition($position);
        }
    }

    private function getPreviousSection(EntityManagerInterface $entityManager, object $entityInstance): ?Sections
    {
        if (!$entityInstance instanceof Courses) {
            return null;
        }

        $originalData = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
        $previousSection = $originalData['section'] ?? null;

        return $previousSection instanceof Sections ? $previousSection : null;
    }

    private function isSameSection(Sections $firstSection, Sections $secondSection): bool
    {
        if ($firstSection === $secondSection) {
            return true;
        }

        return $firstSection->getId() !== null && $firstSection->getId() === $secondSection->getId();
    }

    private function getTwigContent(Courses $course): ?string
    {
        $uploadedFile = $course->getPartialFile();

        if ($uploadedFile !== null && is_readable($uploadedFile->getPathname())) {
            $content = file_get_contents($uploadedFile->getPathname());

            return $content === false ? null : $content;
        }

        return $this->courseFileService->getFileContent($course);
    }
}
