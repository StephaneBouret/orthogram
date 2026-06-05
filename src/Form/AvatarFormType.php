<?php

namespace App\Form;

use App\Entity\Avatar;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Vich\UploaderBundle\Form\Type\VichImageType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AvatarFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('imageFile', VichImageType::class, [
                'required' => false,
                'label' => false,
                'delete_label' => 'Supprimer l\'image',
                'download_uri' => false,
                'attr' => [
                    'class' => 'form-control mb-2'
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Avatar::class,
        ]);
    }
}
