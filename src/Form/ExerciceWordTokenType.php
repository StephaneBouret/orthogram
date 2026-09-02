<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ExerciceWordTokenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('id', HiddenType::class)
            ->add('text', TextType::class, [
                'label' => 'Mot ou groupe de mots',
                'help' => 'Ex. Sarah, l’agence, M. Le Bihan.',
            ])
            ->add('punctuationAfter', TextType::class, [
                'label' => 'Ponctuation après',
                'required' => false,
                'help' => 'Ex. ., ,, :',
            ])
            ->add('joinPrevious', CheckboxType::class, [
                'label' => 'Coller au mot précédent',
                'required' => false,
                'help' => 'Ex. l’agence : cochez cette option sur le token « agence ».',
            ])
            ->add('isAnswer', CheckboxType::class, [
                'label' => 'Bonne réponse',
                'required' => false,
            ])
            ->add('explanation', TextareaType::class, [
                'label' => 'Explication de correction',
                'required' => false,
                'help' => 'Affichée si ce mot est corrigé.',
                'attr' => [
                    'rows' => 2,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
