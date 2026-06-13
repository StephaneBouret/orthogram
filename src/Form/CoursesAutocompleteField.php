<?php

namespace App\Form;

use App\Entity\Courses;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
final class CoursesAutocompleteField extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Courses::class,
            'placeholder' => 'Choisissez un cours',
            'choice_label' => static fn (Courses $course): string => $course->getName() ?? '',
            'searchable_fields' => ['name'],
            'security' => 'ROLE_USER',
            'max_results' => 10,
            'extra_options' => [
                'program_slug' => null,
            ],
            'query_builder' => static function (Options $options): callable {
                return static function (EntityRepository $repository) use ($options) {
                    $queryBuilder = $repository->createQueryBuilder('c')
                        ->join('c.section', 's')
                        ->join('s.program', 'p')
                        ->orderBy('s.position', 'ASC')
                        ->addOrderBy('s.id', 'ASC')
                        ->addOrderBy('c.position', 'ASC')
                        ->addOrderBy('c.id', 'ASC');

                    $programSlug = $options['extra_options']['program_slug'] ?? null;

                    if (is_string($programSlug) && $programSlug !== '') {
                        $queryBuilder
                            ->andWhere('p.slug = :programSlug')
                            ->setParameter('programSlug', $programSlug);
                    }

                    return $queryBuilder;
                };
            },
            'no_results_found_text' => 'Aucun cours trouvé',
            'no_more_results_text' => 'Aucun autre résultat trouvé',
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
