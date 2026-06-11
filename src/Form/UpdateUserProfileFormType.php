<?php

namespace App\Form;

use App\Entity\User;
use libphonenumber\PhoneNumberFormat;
use Misd\PhoneNumberBundle\Form\Type\PhoneNumberType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class UpdateUserProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstname', TextType::class, [
                'label' => 'Prénom',
                'attr' => [
                    'placeholder' => 'Votre prénom',
                ],
            ])
            ->add('lastname', TextType::class, [
                'label' => 'Nom',
                'attr' => [
                    'placeholder' => 'Votre nom',
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse email',
                'mapped' => false,
                'data' => $options['current_email'],
                'attr' => [
                    'placeholder' => 'votre@email.fr',
                ],
            ])
            ->add('emailChangePassword', PasswordType::class, [
                'toggle' => true,
                'hidden_label' => 'Masquer',
                'visible_label' => 'Afficher',
                'label' => 'Mot de passe actuel',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'autocomplete' => 'current-password',
                    'placeholder' => 'Confirmez avec votre mot de passe',
                ],
            ])
            ->add('phone', PhoneNumberType::class, [
                'default_region' => 'FR',
                'format' => PhoneNumberFormat::NATIONAL,
                'number_type' => PhoneNumberType::NUMBER_TYPE_TEL,
                'label' => 'Téléphone',
                'attr' => [
                    'placeholder' => 'Votre téléphone',
                ],
            ])
            ->add('address', TextType::class, [
                'label' => 'Adresse',
                'attr' => [
                    'placeholder' => 'Votre adresse',
                ],
            ])
            ->add('city', TextType::class, [
                'label' => 'Ville',
                'attr' => [
                    'placeholder' => 'Votre ville',
                ],
            ])
            ->add('postalCode', TextType::class, [
                'label' => 'Code postal',
                'attr' => [
                    'placeholder' => 'Votre code postal',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'current_email' => null,
        ]);

        $resolver->setAllowedTypes('current_email', ['null', 'string']);
    }
}
