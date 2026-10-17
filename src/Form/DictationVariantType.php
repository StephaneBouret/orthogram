<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class DictationVariantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('referenceIndex', ChoiceType::class, ['label' => 'Occurrence ciblée', 'choices' => $options['occurrences']])
            ->add('alternatives', TextareaType::class, ['label' => 'Variantes acceptées (une par ligne)', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 520)]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('occurrences')->setAllowedTypes('occurrences', 'array');
    }
}
