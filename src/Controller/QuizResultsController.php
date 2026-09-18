<?php

namespace App\Controller;

use App\Services\QuizResultsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class QuizResultsController extends AbstractController
{
    #[Route('/mes-resultats/tentatives/{id}', name: 'app_quiz_result_correction', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function correction(int $id, QuizResultsService $results): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $correction = $results->correction($id);
        } catch (NotFoundHttpException|AccessDeniedException $error) {
            // A nested AccessDeniedException would make the firewall replace the
            // response and discard these personal-data cache headers.
            throw new HttpException($error instanceof NotFoundHttpException ? 404 : 403, 'Correction indisponible.', headers: $headers);
        }

        return $this->render('quiz_results/correction.html.twig', ['correction' => $correction], new Response(headers: $headers));
    }
}
