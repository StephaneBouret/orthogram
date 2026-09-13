<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

class QuizAnswersType extends AbstractType
{
    public function getParent(): string
    {
        return CollectionType::class;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'entry_type' => QuizAnswerType::class,
            'allow_add' => true,
            'allow_delete' => true,
            'delete_empty' => false,
            'by_reference' => false,
            'error_bubbling' => false,
        ]);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Validate the entire order before ResizeFormListener and before mapping any
        // hidden string to QuizAnswer::setPosition(). Keys remain Symfony row keys,
        // never database identifiers; no entity is looked up from submitted data.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            $valid = is_array($data) || null === $data;
            $data = is_array($data) ? $data : [];
            $positions = [];
            foreach ($data as $key => $row) {
                if (1 !== preg_match('/^(0|[1-9][0-9]*)$/D', (string) $key)) {
                    $valid = false;
                    unset($data[$key]);
                    continue;
                }
                $position = is_array($row) ? ($row['position'] ?? null) : null;
                if (!is_string($position) || 1 !== preg_match('/^(0|[1-9][0-9]*)$/D', $position)
                    || strlen($position) > strlen((string) count($data)) || (int) $position >= count($data)
                    || in_array((int) $position, $positions, true)) {
                    $valid = false;
                } else {
                    $positions[] = (int) $position;
                }
            }

            if (!$valid) {
                $event->getForm()->addError(new FormError('L’ordre des propositions est invalide. Vérifiez la liste puis enregistrez à nouveau.'));
            }

            // Always map safe contiguous integers, even for an invalid request.
            // On failure the form error prevents persistence; ordinary inputs survive.
            $fallbackPosition = 0;
            foreach ($data as $key => $row) {
                $row = is_array($row) ? $row : [];
                $row['position'] = (string) ($valid ? (int) $row['position'] : $fallbackPosition);
                $data[$key] = $row;
                ++$fallbackPosition;
            }
            $event->setData($data);
        }, 100);
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        // Sort only the rendered rows. Keep the original form keys, objects and
        // errors, without clearing or recreating the Doctrine orphanRemoval collection.
        uasort($view->children, static fn (FormView $a, FormView $b): int => (int) $a->children['position']->vars['value'] <=> (int) $b->children['position']->vars['value']);

        // Existing editorial positions may have gaps. Expose the contiguous order
        // expected by the transport contract without changing entities on a GET.
        foreach (array_values($view->children) as $position => $child) {
            $child->children['position']->vars['value'] = (string) $position;
        }
    }
}
