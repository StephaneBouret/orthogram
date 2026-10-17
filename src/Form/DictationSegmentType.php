<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Validator\Constraints as Assert;

final class DictationSegmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['start' => 'Début (ms)', 'end' => 'Fin (ms)'] as $key => $label) {
            $builder->add($key, IntegerType::class, ['label' => $label, 'invalid_message' => 'Saisissez un nombre entier de millisecondes.', 'constraints' => [new Assert\NotNull(message: 'Saisissez cette borne en millisecondes.'), new Assert\PositiveOrZero(message: 'La borne doit être supérieure ou égale à 0 ms.')], 'attr' => ['inputmode' => 'numeric', 'data-bound' => $key]]);
        }
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        foreach (['start', 'end'] as $key) {
            // A number input hides invalid submitted strings in the browser. Keep them visible;
            // IntegerType still performs server-side integer transformation and validation.
            $view[$key]->vars['type'] = 'text';
        }
    }
}
