<?php

namespace App\Controller\Admin;

use App\Entity\ProgramDetail;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ProgramDetailCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ProgramDetail::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('title', 'Objectif pédagogique')
                ->setHelp('Exemple : Comprendre les règles essentielles de l\'orthographe française'),

            TextEditorField::new('content', 'Complément')
                ->setHelp('Optionnel : précision ou texte enrichi associé à cet objectif'),
        ];
    }
}
