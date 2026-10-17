<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Dictation;
use App\Entity\DictationVersion;
use App\Entity\Sections;
use App\Entity\User;
use App\Form\DictationSegmentType;
use App\Form\DictationVariantType;
use App\Services\Dictation\DictationAudioStorage;
use App\Services\Dictation\DictationCorrector;
use App\Services\Dictation\DictationEditorialService;
use App\Services\Dictation\DictationTokenizer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type as Field;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;

#[IsGranted('ROLE_ADMIN')]
#[AdminRoute('/dictees', name: 'dictations', allowedDashboards: [DashboardController::class])]
final class DictationAdminController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly DictationEditorialService $editorial, private readonly DictationTokenizer $tokenizer, private readonly DictationCorrector $corrector)
    {
    }

    #[AdminRoute('', name: 'index', options: ['methods' => ['GET', 'POST']])]
    public function index(Request $request): Response
    {
        $this->authorize();
        $form = $this->createFormBuilder(null, ['csrf_token_id' => 'dictation_create'])
            ->add('section', EntityType::class, ['class' => Sections::class, 'choice_label' => 'name', 'label' => 'Section', 'placeholder' => 'Choisissez une section'])
            ->add('create', Field\SubmitType::class, ['label' => 'Créer la dictée'])->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $dictation = new Dictation($form->get('section')->getData());
                $this->em->persist($dictation);
                $this->em->flush();

                return $this->redirectToRoute('admin_dictations_detail', ['id' => $dictation->getId()]);
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError('Cette section possède déjà une dictée.'));
            }
        }

        return $this->render('admin/dictation/index.html.twig', ['form' => $form, 'dictations' => $this->em->getRepository(Dictation::class)->findBy([], ['id' => 'DESC'])], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[AdminRoute('/{id}', name: 'detail', options: ['methods' => ['GET', 'POST'], 'requirements' => ['id' => '\d+']])]
    public function detail(Dictation $dictation, Request $request): Response
    {
        $this->authorize();
        $form = $this->createFormBuilder(null, ['csrf_token_id' => 'dictation_new_'.$dictation->getId()])
            ->add('title', Field\TextType::class, ['label' => 'Titre', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)]])
            ->add('text', Field\TextareaType::class, ['label' => 'Texte de référence de la dictée', 'help' => 'Saisissez le texte exact dicté, correctement orthographié et ponctué. Il sert de référence pour corriger les copies.', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 20000)]])
            ->add('create', Field\SubmitType::class, ['label' => 'Créer un brouillon'])->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $data = $form->getData();
                $this->corrector->correct($data['text'], $data['text']);
                $version = $this->editorial->createVersion($dictation, $data['title'], $data['text']);

                return $this->redirectToRoute('admin_dictations_version', ['id' => $version->getId()]);
            } catch (\DomainException|\InvalidArgumentException $error) {
                $form->addError(new FormError($error->getMessage()));
            }
        }

        return $this->render('admin/dictation/detail.html.twig', ['dictation' => $dictation, 'form' => $form, 'versions' => $this->em->getRepository(DictationVersion::class)->findBy(['dictation' => $dictation], ['number' => 'DESC']), 'deletableVersionIds' => $this->deletableVersionIds($dictation)], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[AdminRoute('/versions/{id}', name: 'version', options: ['methods' => ['GET', 'POST'], 'requirements' => ['id' => '\d+']])]
    public function version(DictationVersion $version, Request $request): Response
    {
        $this->authorize();
        $deletableVersionIds = $this->deletableVersionIds($version->getDictation());
        $form = $this->versionForm($version, $request->isMethod('POST'));
        $form->handleRequest($request);
        $status = 200;
        if ($form->isSubmitted()) {
            $status = 422;
            if ($version->isPublished()) {
                $status = 409;
                $form->addError(new FormError('Cette version est publiée et immuable. Votre saisie est conservée ci-dessous pour comparaison. Créez une nouvelle version.'));
            } else {
                $this->addSegmentErrors($form->get('segments'), $version->getWritingAudio()['durationMs'] ?? null);
            }
            if ($form->isValid()) {
                try {
                    $data = $form->getData();
                    $variants = array_map(static fn (array $row): array => ['referenceIndex' => $row['referenceIndex'], 'alternatives' => explode("\n", str_replace("\r", '', trim($row['alternatives'])))], array_values($data['variants']));
                    $reviewed = $data['reviewed'] && hash_equals(hash('sha256', $data['text']), $data['textHash']);
                    $version->edit($data['title'], $data['text'], $data['correction'], $variants, $reviewed);
                    $version->editSegments(array_values($data['segments']));
                    $revision = $this->revision($data['revision']);
                    $publish = $form->get('publish');
                    if ($publish instanceof \Symfony\Component\Form\ClickableInterface && $publish->isClicked()) {
                        $this->editorial->publish($version, $revision);
                    } else {
                        // Invalidated variants can be saved for review against the new token list.
                        $this->corrector->correct($data['text'], $data['text'], $version->areVariantsReviewed() ? $variants : []);
                        $this->editorial->saveDraft($version, $revision);
                    }
                    $this->addFlash('success', $version->isPublished() ? 'Version publiée et figée.' : 'Brouillon enregistré.');

                    return $this->redirectToRoute('admin_dictations_version', ['id' => $version->getId()]);
                } catch (ConflictHttpException|\DomainException|\InvalidArgumentException $error) {
                    $status = $error instanceof ConflictHttpException ? 409 : 422;
                    $form->addError(new FormError($error->getMessage()));
                }
            }
        }

        return $this->render('admin/dictation/version.html.twig', ['version' => $version, 'form' => $form, 'deletableVersionIds' => $deletableVersionIds], new Response(status: $status));
    }

    #[AdminRoute('/{dictationId}/versions/{id}/delete', name: 'delete', options: ['methods' => ['POST'], 'requirements' => ['dictationId' => '\d+', 'id' => '\d+']])]
    public function deleteDraft(int $dictationId, int $id, Request $request): JsonResponse
    {
        try {
            $this->authorize();
            if (!$this->isCsrfTokenValid('dictation_delete_'.$dictationId.'_'.$id, $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez après avoir préservé votre saisie.');
            }
            $this->editorial->deleteDraft($dictationId, $id, $this->revision($request->request->get('revision')));
            $this->addFlash('success', 'Le brouillon a été supprimé. Les autres versions et les fichiers audio sont conservés.');

            return $this->json(['redirect' => $this->generateUrl('admin_dictations_detail', ['id' => $dictationId])]);
        } catch (HttpException|AccessDeniedException $error) {
            return $this->json(['message' => $error->getMessage()], $error instanceof HttpException ? $error->getStatusCode() : 403);
        }
    }

    /** Visibility only; deletion rechecks all conditions under transaction locks.
     * @return list<int>
     */
    private function deletableVersionIds(Dictation $dictation): array
    {
        $rows = $this->em->createQuery('SELECT v.id FROM App\Entity\DictationVersion v WHERE v.dictation = :dictation AND v.publishedAt IS NULL AND NOT EXISTS (SELECT a.id FROM App\Entity\DictationAttempt a WHERE a.version = v)')
            ->setParameter('dictation', $dictation)->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    #[AdminRoute('/versions/{id}/clone', name: 'clone', options: ['methods' => ['POST'], 'requirements' => ['id' => '\d+']])]
    public function duplicate(DictationVersion $version, Request $request): Response
    {
        $this->authorize();
        $this->checkToken($request, 'clone', $version);
        $copy = $this->editorial->cloneVersion($version, $this->revision($request->request->get('revision')));

        return $this->redirectToRoute('admin_dictations_version', ['id' => $copy->getId()]);
    }

    #[AdminRoute('/versions/{id}/audio/{role}', name: 'upload', options: ['methods' => ['POST'], 'requirements' => ['id' => '\d+', 'role' => 'complete|writing']])]
    public function upload(DictationVersion $version, string $role, Request $request): Response
    {
        $this->authorize();
        $this->checkToken($request, 'upload_'.$role, $version);
        $file = $request->files->get('audio');
        if (!$file instanceof UploadedFile) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Choisissez un fichier MP3 (25 Mio maximum).');
        }
        try {
            $this->editorial->importAudio($version, $this->revision($request->request->get('revision')), $role, $file);
            $this->addFlash('success', 'Piste importée.'.('writing' === $role ? ' Saisissez et enregistrez ses segments.' : ''));
        } catch (\DomainException|\RuntimeException $error) {
            if ($error instanceof ConflictHttpException) {
                throw $error;
            }
            $this->addFlash('danger', $error->getMessage());
        }

        return $this->redirectToRoute('admin_dictations_version', ['id' => $version->getId()]);
    }

    #[AdminRoute('/versions/{id}/audio/{role}/listen', name: 'audio', options: ['methods' => ['GET', 'HEAD'], 'requirements' => ['id' => '\d+', 'role' => 'complete|writing']])]
    public function audio(DictationVersion $version, string $role, DictationAudioStorage $storage): BinaryFileResponse
    {
        $this->authorize();
        $audio = 'complete' === $role ? $version->getCompleteAudio() : $version->getWritingAudio();
        if (null === $audio) {
            throw $this->createNotFoundException();
        }
        try {
            $path = $storage->resolve($audio);
        } catch (\DomainException) {
            throw $this->createNotFoundException();
        }

        return new BinaryFileResponse($path, headers: ['Content-Type' => 'audio/mpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], public: false);
    }

    private function versionForm(DictationVersion $version, bool $submitted = false): FormInterface
    {
        $occurrences = [];
        foreach ($this->tokenizer->tokenize($version->getCanonicalText())->words as $index => $word) {
            $occurrences[($index + 1).' — '.$word['value']] = $index;
        }
        foreach ($version->getVariants() as $variant) {
            if (!in_array($variant['referenceIndex'], $occurrences, true)) {
                $occurrences['Ancienne occurrence '.($variant['referenceIndex'] + 1).' — cible à remplacer ou supprimer'] = $variant['referenceIndex'];
            }
        }
        $data = ['title' => $version->getTitle(), 'text' => $version->getCanonicalText(), 'correction' => $version->getCorrectionContent(),
            'variants' => array_map(static fn (array $row): array => ['referenceIndex' => $row['referenceIndex'], 'alternatives' => implode("\n", $row['alternatives'])], $version->getVariants()),
            'segments' => $version->getSegments(), 'reviewed' => false, 'textHash' => hash('sha256', $version->getCanonicalText()), 'revision' => (string) $version->getRevision()];

        return $this->createFormBuilder($data, ['csrf_token_id' => 'dictation_version_'.$version->getId(), 'disabled' => $version->isPublished() && !$submitted])
            ->add('revision', Field\HiddenType::class)->add('textHash', Field\HiddenType::class)
            ->add('title', Field\TextType::class, ['label' => 'Titre', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)]])
            ->add('text', Field\TextareaType::class, ['label' => 'Texte de référence de la dictée', 'help' => 'Saisissez le texte exact dicté, correctement orthographié et ponctué. Il sert de référence pour corriger les copies.', 'trim' => false, 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 20000)], 'attr' => ['rows' => 6]])
            ->add('correction', Field\TextareaType::class, ['label' => 'Explications de Caroline — facultatif', 'help' => 'Ajoutez les explications pédagogiques destinées à la fin du parcours. Il n’est pas nécessaire de recopier le texte de référence.', 'required' => false, 'trim' => false, 'constraints' => [new Assert\Length(max: 20000)]])
            ->add('variants', Field\CollectionType::class, ['entry_type' => DictationVariantType::class, 'entry_options' => ['occurrences' => $occurrences, 'label' => false], 'allow_add' => true, 'allow_delete' => true, 'constraints' => [new Assert\Count(max: 32)]])
            ->add('reviewed', Field\CheckboxType::class, ['label' => 'J’ai vérifié les variantes pour le texte enregistré, ou confirmé qu’aucune variante n’est nécessaire', 'required' => false])
            ->add('segments', Field\CollectionType::class, ['entry_type' => DictationSegmentType::class, 'entry_options' => ['label' => false], 'allow_add' => true, 'allow_delete' => true, 'constraints' => [new Assert\Count(max: 200)]])
            ->add('save', Field\SubmitType::class, ['label' => 'Enregistrer le brouillon'])
            ->add('publish', Field\SubmitType::class, ['label' => 'Publier la version enregistrée'])->getForm();
    }

    private function addSegmentErrors(FormInterface $segments, ?int $duration): void
    {
        $previousEnd = null;
        $number = 0;
        foreach ($segments as $row) {
            ++$number;
            $start = $row->get('start')->isSynchronized() ? $row->get('start')->getData() : null;
            $end = $row->get('end')->isSynchronized() ? $row->get('end')->getData() : null;
            // Transformation/required/nonnegative errors already belong to the individual fields.
            if (is_int($start) && $start >= 0 && is_int($previousEnd) && $start < $previousEnd) {
                $row->get('start')->addError(new FormError(sprintf('Le début doit être supérieur ou égal à la fin du segment %d (%d ms).', $number - 1, $previousEnd)));
            }
            if (is_int($start) && is_int($end) && $end <= $start) {
                $row->get('end')->addError(new FormError(sprintf('La fin doit être strictement supérieure au début (%d ms).', $start)));
            }
            if (null === $duration) {
                $row->get('end')->addError(new FormError('Importez la piste d’écriture avant de définir ses segments.'));
            } else {
                foreach (['start' => $start, 'end' => $end] as $key => $value) {
                    if (is_int($value) && $value > $duration) {
                        $row->get($key)->addError(new FormError(sprintf('Cette borne dépasse la durée de la piste (%d ms).', $duration)));
                    }
                }
            }
            $previousEnd = is_int($end) && $end >= 0 ? $end : null;
        }
    }

    private function revision(mixed $value): int
    {
        if (!is_string($value) || !ctype_digit($value) || (string) (int) $value !== $value || (int) $value < 1) {
            throw new ConflictHttpException('Révision attendue manquante ou invalide.');
        }

        return (int) $value;
    }

    private function checkToken(Request $request, string $action, DictationVersion $version): void
    {
        if (!$this->isCsrfTokenValid('dictation_'.$action.'_'.$version->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    private function authorize(): void
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isAccountActive() || !$this->isGranted('IS_AUTHENTICATED_FULLY')) {
            throw $this->createAccessDeniedException();
        }
    }
}
