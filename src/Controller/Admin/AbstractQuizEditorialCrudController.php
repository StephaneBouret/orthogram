<?php

namespace App\Controller\Admin;

use App\Entity\Quiz;
use App\Entity\QuizQuestion;
use App\Exception\InvalidQuizConfiguration;
use App\Services\QuizEditorialService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

abstract class AbstractQuizEditorialCrudController extends AbstractCrudController
{
    private ?Quiz $originalQuiz = null;

    public function __construct(protected readonly QuizEditorialService $editorial, protected readonly EntityManagerInterface $em)
    {
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add('index', Action::DETAIL)->disable(Action::BATCH_DELETE);
    }

    public function edit(AdminContext $context): KeyValueStore|Response
    {
        if ($context->getRequest()->isXmlHttpRequest() || !$context->getRequest()->isMethodSafe() && !$context->getRequest()->isMethod('POST')) {
            throw new MethodNotAllowedHttpException(['GET', 'POST'], 'Utilisez le formulaire pour modifier la configuration du quiz.');
        }

        return $this->editorialAction($context, fn () => parent::edit($context));
    }

    public function new(AdminContext $context): KeyValueStore|Response
    {
        return $this->editorialAction($context, fn () => parent::new($context));
    }

    public function delete(AdminContext $context): KeyValueStore|Response
    {
        try {
            return $this->editorialAction($context, fn () => parent::delete($context));
        } catch (InvalidQuizConfiguration $error) {
            $this->addFlash('danger', 'Suppression refusée. '.$error->getMessage());

            return $this->redirect($this->container->get(AdminUrlGeneratorInterface::class)->setController(static::class)->setAction(Action::INDEX)->unset('entityId')->generateUrl());
        }
    }

    public function batchDelete(AdminContext $context, mixed $batchActionDto = null): Response
    {
        throw $this->createAccessDeniedException('Supprimez les questions individuellement pour vérifier la composition du quiz.');
    }

    /** @param callable(): (KeyValueStore|Response) $action */
    private function editorialAction(AdminContext $context, callable $action): KeyValueStore|Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if ($context->getRequest()->isMethodSafe()) {
            return $action();
        }
        $entity = $context->getEntity()->getInstance();
        $data = $context->getRequest()->request->all('QuizQuestion');
        $target = $data['quiz'] ?? null;
        $targetId = is_scalar($target) && ctype_digit((string) $target) ? (int) $target : null;

        return $this->editorial->transaction($entity instanceof Quiz || $entity instanceof QuizQuestion ? $entity : null, $targetId, function () use ($context, $action): KeyValueStore|Response {
            $entity = $context->getEntity()->getInstance();
            $this->originalQuiz = $entity instanceof QuizQuestion ? $entity->getQuiz() : null;
            $result = $action();
            if ($result instanceof KeyValueStore) {
                // Invalid forms retain their detached submitted objects for rendering,
                // without leaving a dirty managed graph that a later flush could write.
                $this->em->clear();
            }

            return $result;
        });
    }

    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->validateForm(parent::createEditFormBuilder($entityDto, $formOptions, $context));
    }

    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->validateForm(parent::createNewFormBuilder($entityDto, $formOptions, $context));
    }

    private function validateForm(FormBuilderInterface $builder): FormBuilderInterface
    {
        return $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            if (!$form->isValid()) {
                return;
            }
            $entity = $form->getData();
            try {
                $this->editorial->validate($entity, $this->originalQuiz);
            } catch (InvalidQuizConfiguration $error) {
                $field = $entity instanceof Quiz ? ($entity->isRenewalEnabled() ? 'renewalEnabled' : 'errorQuestionQuota') : 'quiz';
                if ($entity instanceof Quiz && $form->has($error->propertyPath ?? '')) {
                    $field = $error->propertyPath;
                } elseif ($entity instanceof QuizQuestion && $entity->getId() === $error->questionId && $form->has($error->propertyPath ?? '')) {
                    $field = $error->propertyPath;
                }
                $form->get($field)->addError(new FormError($error->getMessage()));
            }
        }, -2048);
    }

    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->editorial->validate($entityInstance, $this->originalQuiz);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->editorial->validate($entityInstance, $this->originalQuiz);
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if ($entityInstance instanceof QuizQuestion) {
            $quiz = $entityInstance->getQuiz();
            $quiz?->removeQuestion($entityInstance);
            $this->editorial->validate($entityInstance, $quiz);
        }
        parent::deleteEntity($entityManager, $entityInstance);
    }
}
