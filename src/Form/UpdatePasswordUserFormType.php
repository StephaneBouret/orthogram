<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PasswordStrength;

final class UpdatePasswordUserFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('oldPassword', PasswordType::class, [
                'toggle' => true,
                'hidden_label' => 'Masquer',
                'visible_label' => 'Afficher',
                'label' => 'Mot de passe actuel',
                'mapped' => false,
                'required' => true,
                'attr' => [
                    'autocomplete' => 'current-password',
                    'placeholder' => 'Votre mot de passe actuel',
                ],
                'constraints' => [
                    new NotBlank(message: 'Merci de saisir votre mot de passe actuel.'),
                    new UserPassword(message: 'Le mot de passe actuel est incorrect.'),
                ],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => true,
                'invalid_message' => 'Le mot de passe et sa confirmation doivent être identiques.',
                'constraints' => [
                    new NotBlank(message: 'Merci de saisir un nouveau mot de passe.'),
                    new PasswordStrength(
                        minScore: PasswordStrength::STRENGTH_STRONG,
                        message: 'Le mot de passe est trop faible. Veuillez utiliser un mot de passe plus fort.'
                    ),
                ],
                'first_options' => [
                    'toggle' => true,
                    'hidden_label' => 'Masquer',
                    'visible_label' => 'Afficher',
                    'label' => 'Nouveau mot de passe',
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'placeholder' => 'Nouveau mot de passe',
                        'data-reset-password-target' => 'password',
                    ],
                ],
                'second_options' => [
                    'toggle' => true,
                    'hidden_label' => 'Masquer',
                    'visible_label' => 'Afficher',
                    'label' => 'Confirmation',
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'placeholder' => 'Confirmer le nouveau mot de passe',
                    ],
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
