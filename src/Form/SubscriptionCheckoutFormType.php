<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

class SubscriptionCheckoutFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('agreeTerms', CheckboxType::class, [
                'mapped' => false,
                'constraints' => [
                    new IsTrue(
                        message: 'Vous devez accepter les conditions générales de vente.',
                    ),
                ]
            ])
            ->add('waiveWithdrawalRight', CheckboxType::class, [
                'mapped' => false,
                'label' => false,
                'constraints' => [
                    new IsTrue(
                        message: 'Vous devez confirmer votre demande d\'accès immédiat et votre renoncement au droit de rétractation.',
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Configure your form options here
        ]);
    }
}
