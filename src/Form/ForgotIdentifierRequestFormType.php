<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Blank;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class ForgotIdentifierRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('requestedIdentifier', EmailType::class, [
                'label' => 'Nouvel identifiant souhaité',
                'constraints' => [
                    new NotBlank(message: 'Merci d’indiquer le nouvel identifiant souhaité.'),
                    new Email(message: 'L’adresse email {{ value }} n’est pas valide.'),
                    new Length(max: 180, maxMessage: 'L’identifiant ne peut pas dépasser {{ limit }} caractères.'),
                ],
                'attr' => [
                    'autocomplete' => 'email',
                    'placeholder' => 'nouvelle-adresse@email.fr',
                ],
            ])
            ->add('firstname', TextType::class, [
                'label' => 'Prénom',
                'constraints' => [
                    new NotBlank(message: 'Merci d’indiquer votre prénom.'),
                    new Length(max: 80, maxMessage: 'Le prénom ne peut pas dépasser {{ limit }} caractères.'),
                ],
                'attr' => [
                    'autocomplete' => 'given-name',
                    'placeholder' => 'Votre prénom',
                ],
            ])
            ->add('lastname', TextType::class, [
                'label' => 'Nom',
                'constraints' => [
                    new NotBlank(message: 'Merci d’indiquer votre nom.'),
                    new Length(max: 80, maxMessage: 'Le nom ne peut pas dépasser {{ limit }} caractères.'),
                ],
                'attr' => [
                    'autocomplete' => 'family-name',
                    'placeholder' => 'Votre nom',
                ],
            ])
            ->add('phone', TextType::class, [
                'label' => 'Téléphone',
                'constraints' => [
                    new NotBlank(message: 'Merci d’indiquer votre numéro de téléphone.'),
                    new Length(max: 40, maxMessage: 'Le téléphone ne peut pas dépasser {{ limit }} caractères.'),
                ],
                'attr' => [
                    'autocomplete' => 'tel',
                    'placeholder' => 'Votre téléphone',
                ],
            ])
            ->add('postalCode', TextType::class, [
                'label' => 'Code postal',
                'required' => false,
                'constraints' => [
                    new Length(max: 20, maxMessage: 'Le code postal ne peut pas dépasser {{ limit }} caractères.'),
                ],
                'attr' => [
                    'autocomplete' => 'postal-code',
                    'placeholder' => 'Votre code postal',
                ],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Message',
                'required' => false,
                'constraints' => [
                    new Length(max: 1000, maxMessage: 'Le message ne peut pas dépasser {{ limit }} caractères.'),
                ],
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'Ajoutez une précision utile si nécessaire',
                ],
            ])
            ->add('website', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new Blank(),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'forgot_identifier_request',
        ]);
    }
}
