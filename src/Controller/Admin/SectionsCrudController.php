<?php

namespace App\Controller\Admin;

use App\Entity\Program;
use App\Entity\Sections;
use App\Repository\SectionsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class SectionsCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly SectionsRepository $sectionsRepository,
    ) {
    }

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
            ->setDefaultSort(['position' => 'ASC', 'id' => 'ASC'])
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
            IntegerField::new('position', 'Ordre d’affichage')
                ->hideWhenCreating()
                ->setHelp('0 place la section au début. Changer la position réordonne automatiquement les autres sections du programme.'),
            TextareaField::new('shortDescription', 'Description courte'),
            AssociationField::new('program', 'Programme de formation')
            ->setQueryBuilder(
                fn (QueryBuilder $queryBuilder) => $queryBuilder->getEntityManager()->getRepository(Program::class)->createQueryBuilder('p')->orderBy('p.name', 'ASC')
            )
            ->autocomplete(),
        ];
    }

    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->setPositionIfMissing($entityInstance);
        $this->reorderSection($entityInstance);

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $previousProgram = $this->getPreviousProgram($entityManager, $entityInstance);

        $this->reorderSection($entityInstance, $previousProgram);

        parent::updateEntity($entityManager, $entityInstance);
    }

    private function setPositionIfMissing(object $entityInstance): void
    {
        if (!$entityInstance instanceof Sections) {
            return;
        }

        $program = $entityInstance->getProgram();

        if (null === $program || 0 !== $entityInstance->getPosition()) {
            return;
        }

        $entityInstance->setPosition($this->sectionsRepository->getNextPositionForProgram($program));
    }

    private function reorderSection(object $entityInstance, ?Program $previousProgram = null): void
    {
        if (!$entityInstance instanceof Sections) {
            return;
        }

        $program = $entityInstance->getProgram();

        if (null === $program) {
            return;
        }

        if (null !== $previousProgram && !$this->isSameProgram($previousProgram, $program)) {
            $this->reindexProgram($previousProgram, $entityInstance);
        }

        $sections = $this->sectionsRepository->findOrderedByProgramExcluding($program, $entityInstance);
        $targetPosition = max(0, min($entityInstance->getPosition() ?? 0, count($sections)));

        array_splice($sections, $targetPosition, 0, [$entityInstance]);

        foreach ($sections as $position => $section) {
            $section->setPosition($position);
        }
    }

    private function reindexProgram(Program $program, Sections $excludedSection): void
    {
        foreach ($this->sectionsRepository->findOrderedByProgramExcluding($program, $excludedSection) as $position => $section) {
            $section->setPosition($position);
        }
    }

    private function getPreviousProgram(EntityManagerInterface $entityManager, object $entityInstance): ?Program
    {
        if (!$entityInstance instanceof Sections) {
            return null;
        }

        $originalData = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
        $previousProgram = $originalData['program'] ?? null;

        return $previousProgram instanceof Program ? $previousProgram : null;
    }

    private function isSameProgram(Program $firstProgram, Program $secondProgram): bool
    {
        if ($firstProgram === $secondProgram) {
            return true;
        }

        return null !== $firstProgram->getId() && $firstProgram->getId() === $secondProgram->getId();
    }
}
