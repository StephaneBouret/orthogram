<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\PasswordStrength;

class ResetPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'required' => true,
                'invalid_message' => 'Le mot de passe et la confirmation doivent être identiques',
                'mapped' => false,
                'attr' => [
                    'autocomplete' => 'new-password',
                    'placeholder' => 'Merci de saisir votre mot de passe',
                ],
                'constraints' => [
                    new PasswordStrength(
                        minScore: PasswordStrength::STRENGTH_STRONG,
                        message: 'Le mot de passe est trop faible. Veuillez utiliser un mot de passe plus fort.'
                    ),
                ],
                'first_options' => [
                    'toggle' => true,
                    'hidden_label' => 'Masquer',
                    'visible_label' => 'Afficher',
                    'label' => 'Votre mot de passe :',
                    'label_attr' => [
                        'class' => 'lh-label fw-bold form-label',
                    ],
                    'row_attr' => [
                        'class' => 'mb-3',
                    ],
                    'attr' => [
                        'placeholder' => 'Merci de saisir votre mot de passe',
                        'class' => 'form-control form-control-login',
                        'data-reset-password-target' => 'password',
                    ],
                ],
                'second_options' => [
                    'toggle' => true,
                    'hidden_label' => 'Masquer',
                    'visible_label' => 'Afficher',
                    'label' => 'Confirmez votre mot de passe :',
                    'label_attr' => [
                        'class' => 'lh-label fw-bold form-label',
                    ],
                    'row_attr' => [
                        'class' => 'mb-3',
                    ],
                    'attr' => [
                        'placeholder' => 'Merci de confirmer votre mot de passe',
                        'class' => 'form-control form-control-login',
                    ],
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Configure your form options here
        ]);
    }
}
