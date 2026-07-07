<?php

namespace App\Form;

use App\Entity\Avatar;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Vich\UploaderBundle\Form\Type\VichImageType;

class AvatarFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('imageFile', VichImageType::class, [
                'required' => false,
                'label' => false,
                'allow_delete' => $options['allow_delete'],
                'delete_label' => 'Supprimer l\'image',
                'download_uri' => false,
                'image_uri' => $options['image_uri'],
                'attr' => [
                    'class' => 'form-control mb-2',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Avatar::class,
            'allow_delete' => true,
            'image_uri' => true,
        ]);

        $resolver->setAllowedTypes('allow_delete', 'bool');
        $resolver->setAllowedTypes('image_uri', ['bool', 'string', 'callable']);
    }
}
