<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CourseSearchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('course', CoursesAutocompleteField::class, [
            'label' => false,
            'multiple' => false,
            'extra_options' => [
                'program_slug' => $options['program_slug'],
            ],
            'attr' => [
                'data-course-search-target' => 'select',
                'data-action' => 'change->course-search#navigate',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'program_slug' => null,
        ]);

        $resolver->setAllowedTypes('program_slug', ['null', 'string']);
    }
}
