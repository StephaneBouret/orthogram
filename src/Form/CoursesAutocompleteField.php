<?php

namespace App\Form;

use App\Entity\Courses;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
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
            'placeholder' => 'Tapez au moins 2 lettres',
            'choice_label' => static fn (Courses $course): string => $course->getName() ?? '',
            'choice_lazy' => true,
            'min_characters' => 2,
            'preload' => 'false',
            'security' => 'ROLE_USER',
            'max_results' => 10,
            'extra_options' => [
                'program_slug' => null,
            ],
            'filter_query' => static function (QueryBuilder $queryBuilder, string $query): void {
                $queryBuilder->setMaxResults(10);

                if ('' === $query) {
                    return;
                }

                $queryBuilder
                    ->andWhere("LOWER(c.name) LIKE :courseSearch ESCAPE '\\'")
                    ->setParameter('courseSearch', '%'.addcslashes(mb_strtolower($query), '\\%_').'%');
            },
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

                    if (is_string($programSlug) && '' !== $programSlug) {
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
