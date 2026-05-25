<?php

namespace App\Controller\Admin;

use App\Entity\ProgramHighlight;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ProgramHighlightCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ProgramHighlight::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('title', 'Titre du bloc')
                ->setHelp('Exemple : Les bases indispensables'),

            TextEditorField::new('content', 'Contenu du bloc')
                ->setHelp('Peut contenir du texte formaté en HTML'),
        ];
    }
}
