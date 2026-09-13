<?php

namespace App\Form;

use App\Entity\QuizAnswer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class QuizAnswerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('content', TextareaType::class, ['label' => 'Proposition', 'empty_data' => ''])
            ->add('correct', CheckboxType::class, ['label' => 'Bonne réponse', 'required' => false])
            ->add('position', HiddenType::class, ['attr' => ['data-quiz-answer-position' => '']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => QuizAnswer::class]);
    }
}
