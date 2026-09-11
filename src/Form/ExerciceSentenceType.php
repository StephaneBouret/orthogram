<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ExerciceSentenceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('id', HiddenType::class)
            ->add('noAnswer', CheckboxType::class, [
                'label' => "Cette phrase n'a aucune réponse à sélectionner",
                'attr' => ['data-no-answer-toggle' => ''],
                'required' => false,
            ])
            ->add('noAnswerExplanation', TextareaType::class, [
                'label' => 'Explication',
                'attr' => ['placeholder' => 'Aucun nom dans cette phrase.'],
                'row_attr' => ['data-no-answer-detail' => ''],
                'help' => 'Explication affichée lors de la correction lorsque aucun mot ne doit être sélectionné.',
                'required' => false,
            ])
            ->add('words', CollectionType::class, [
                'label' => 'Mots de la phrase',
                'entry_type' => ExerciceWordTokenType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'entry_options' => [
                    'label' => false,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'attr' => ['data-exercice-sentence' => ''],
        ]);
    }
}
