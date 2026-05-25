<?php

namespace App\Controller;

use App\Entity\Program;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProgramController extends AbstractController
{
    #[Route('/program/{slug}', name: 'app_program_show', methods: ['GET'])]
    public function __invoke(
        #[MapEntity(mapping: ['slug' => 'slug'])]
        Program $program
    ): Response
    {
        return $this->render('program/index.html.twig', [
            'program' => $program,
        ]);
    }
}
