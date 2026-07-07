<?php

namespace App\Controller\Admin;

use App\Entity\Exercice;
use App\Form\ExerciceSentenceType;
use App\Repository\ExerciceRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ExerciceCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Exercice::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityPermission('ROLE_ADMIN')
            ->setEntityLabelInSingular('Exercice')
            ->setEntityLabelInPlural('Exercices')
            ->setPageTitle(Crud::PAGE_INDEX, 'Exercices')
            ->setPageTitle(Crud::PAGE_NEW, 'Créer un exercice')
            ->setPageTitle(Crud::PAGE_DETAIL, fn (Exercice $exercice): string => (string) $exercice)
            ->setPageTitle(Crud::PAGE_EDIT, fn (Exercice $exercice): string => sprintf('Modifier %s', (string) $exercice))
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(20)
            ->showEntityActionsInlined()
            ->overrideTemplate('crud/edit', 'admin/exercice/edit.html.twig')
            ->overrideTemplate('crud/new', 'admin/exercice/new.html.twig');
    }

    public function configureActions(Actions $actions): Actions
    {
        $importJson = Action::new('importJson', 'Importer JSON', 'fa fa-file-import')
            ->linkToCrudAction('importJson')
            ->asPrimaryAction();

        $createFromJson = Action::new('createFromJson', 'Créer via JSON', 'fa fa-file-import')
            ->createAsGlobalAction()
            ->linkToCrudAction('importNewJson')
            ->asPrimaryAction();

        return $actions
            ->add(Crud::PAGE_INDEX, $createFromJson)
            ->add(Crud::PAGE_INDEX, $importJson)
            ->add(Crud::PAGE_DETAIL, $importJson)
            ->add(Crud::PAGE_EDIT, $importJson);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title', 'Titre'),
            TextareaField::new('instruction', 'Consigne')
                ->hideOnIndex(),
            ChoiceField::new('type', 'Type')
                ->setChoices([
                    'Cliquer sur les bons mots' => Exercice::TYPE_CLICK_WORDS,
                ])
                ->renderAsBadges([
                    Exercice::TYPE_CLICK_WORDS => 'success',
                ]),
            CollectionField::new('sentences', 'Phrases')
                ->setEntryType(ExerciceSentenceType::class)
                ->allowAdd()
                ->allowDelete()
                ->setFormTypeOption('by_reference', false)
                ->setHelp('Ajoutez les mots dans l’ordre. Un groupe comme “M. Le Bihan” reste un seul mot cliquable.')
                ->hideOnIndex(),
            AssociationField::new('courses', 'Cours associés')
                ->onlyOnDetail(),
        ];
    }

    #[AdminRoute(path: '/import-json', name: 'import_json')]
    public function importJson(
        AdminContext $context,
        Request $request,
        ExerciceRepository $exerciceRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $exercice = $this->getExerciceFromContext($context, $request, $exerciceRepository);

        if (!$exercice instanceof Exercice) {
            $this->addFlash('warning', 'Exercice introuvable.');

            return $this->redirectToExerciceIndex();
        }

        $jsonContent = $request->request->getString('json_content');

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('import_exercice_json_'.$exercice->getId(), $request->request->getString('_token'))) {
                $this->addFlash('warning', 'Le jeton de sécurité est invalide.');

                return $this->redirectToRoute('admin_exercice_import_json', ['entityId' => $exercice->getId()]);
            }

            try {
                $payload = $this->decodeImportedJson($jsonContent);
                $this->applyImportedPayload($exercice, $payload);
                $entityManager->flush();

                $this->addFlash('success', 'Les données de l’exercice ont été importées.');

                return $this->redirectToRoute('admin_exercice_edit', ['entityId' => $exercice->getId()]);
            } catch (\JsonException $e) {
                $this->addFlash('warning', sprintf('JSON invalide : %s', $e->getMessage()));
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('warning', $e->getMessage());
            }
        }

        return $this->render('admin/exercice/import_json.html.twig', [
            'exercice' => $exercice,
            'isCreation' => false,
            'jsonContent' => $jsonContent,
            'title' => $exercice->getTitle(),
            'instruction' => $exercice->getInstruction(),
            'type' => $exercice->getType(),
        ]);
    }

    #[AdminRoute(path: '/import-json/new', name: 'import_json_new')]
    public function importNewJson(
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $jsonContent = $request->request->getString('json_content');
        $title = $request->request->getString('title');
        $instruction = $request->request->getString('instruction');
        $type = $request->request->getString('type', Exercice::TYPE_CLICK_WORDS);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('import_exercice_json_new', $request->request->getString('_token'))) {
                $this->addFlash('warning', 'Le jeton de sécurité est invalide.');

                return $this->redirectToRoute('admin_exercice_import_json_new');
            }

            try {
                $payload = $this->decodeImportedJson($jsonContent);
                $exercice = (new Exercice())
                    ->setTitle($this->resolveTextValue($payload, 'title', $title, 'Le titre est obligatoire.'))
                    ->setInstruction($this->resolveTextValue($payload, 'instruction', $instruction, 'La consigne est obligatoire.'))
                    ->setType($this->resolveTextValue($payload, 'type', $type, 'Le type est obligatoire.'));

                $this->applyImportedPayload($exercice, $payload);
                $entityManager->persist($exercice);
                $entityManager->flush();

                $this->addFlash('success', 'L’exercice a été créé depuis le JSON.');

                return $this->redirectToRoute('admin_exercice_edit', ['entityId' => $exercice->getId()]);
            } catch (\JsonException $e) {
                $this->addFlash('warning', sprintf('JSON invalide : %s', $e->getMessage()));
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('warning', $e->getMessage());
            }
        }

        return $this->render('admin/exercice/import_json.html.twig', [
            'exercice' => null,
            'isCreation' => true,
            'jsonContent' => $jsonContent,
            'title' => $title,
            'instruction' => $instruction,
            'type' => $type,
        ]);
    }

    private function getExerciceFromContext(
        AdminContext $context,
        Request $request,
        ExerciceRepository $exerciceRepository,
    ): ?Exercice {
        $entity = $context->getEntity()?->getInstance();

        if ($entity instanceof Exercice) {
            return $entity;
        }

        $entityId = $request->query->get('entityId');

        return $entityId ? $exerciceRepository->find($entityId) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeImportedJson(string $jsonContent): array
    {
        $jsonContent = $this->extractJsonObject($jsonContent);

        if ('' === $jsonContent) {
            throw new \InvalidArgumentException('Collez un contenu JSON avant de lancer l’import.');
        }

        $jsonContent = preg_replace('/,\s*([}\]])/', '$1', $jsonContent) ?? $jsonContent;
        $payload = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Le JSON importé doit être un objet.');
        }

        return $payload;
    }

    private function extractJsonObject(string $jsonContent): string
    {
        $jsonContent = trim($jsonContent);
        $jsonContent = preg_replace('/^```(?:json)?\s*/i', '', $jsonContent) ?? $jsonContent;
        $jsonContent = preg_replace('/\s*```$/', '', $jsonContent) ?? $jsonContent;

        $start = strpos($jsonContent, '{');
        $end = strrpos($jsonContent, '}');

        if (false === $start || false === $end || $end < $start) {
            return $jsonContent;
        }

        return substr($jsonContent, $start, $end - $start + 1);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyImportedPayload(Exercice $exercice, array $payload): void
    {
        if (isset($payload['title']) && is_string($payload['title']) && '' !== trim($payload['title'])) {
            $exercice->setTitle(trim($payload['title']));
        }

        if (isset($payload['instruction']) && is_string($payload['instruction']) && '' !== trim($payload['instruction'])) {
            $exercice->setInstruction(trim($payload['instruction']));
        }

        if (isset($payload['type']) && is_string($payload['type']) && '' !== trim($payload['type'])) {
            $exercice->setType(trim($payload['type']));
        }

        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;

        if (!isset($data['sentences']) || !is_array($data['sentences'])) {
            throw new \InvalidArgumentException('Le JSON doit contenir une clé "sentences".');
        }

        $exercice->setData($data);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveTextValue(array $payload, string $key, string $fallback, string $errorMessage): string
    {
        $value = isset($payload[$key]) && is_string($payload[$key]) ? $payload[$key] : $fallback;
        $value = trim($value);

        if ('' === $value) {
            throw new \InvalidArgumentException($errorMessage);
        }

        return $value;
    }

    private function redirectToExerciceIndex(): RedirectResponse
    {
        return $this->redirectToRoute('admin_exercice_index');
    }
}
