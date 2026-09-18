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
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

#[IsGranted('ROLE_USER')]
final class QuizResultsController extends AbstractController
{
    #[Route('/mes-resultats', name: 'app_quiz_results', methods: ['GET'])]
    public function index(QuizResultsService $results, ChartBuilderInterface $chartBuilder): Response
    {
        $cards = $results->results();
        usort($cards, static fn (array $a, array $b) => $a['catalogOrder'] <=> $b['catalogOrder'] ?: strcmp($a['key'], $b['key']));
        $sections = [];
        $archives = [];
        foreach ($cards as $card) {
            $latest = $card['latest'];
            $card['chart'] = null;
            if (null !== $latest && $latest['total'] > 0 && $latest['score'] >= 0 && $latest['score'] <= $latest['total']) {
                $chart = $chartBuilder->createChart(Chart::TYPE_DOUGHNUT);
                $chart->setData([
                    'labels' => ['Réponses correctes', 'Réponses incorrectes'],
                    'datasets' => [['data' => [$latest['score'], $latest['total'] - $latest['score']], 'borderWidth' => 0]],
                ]);
                $chart->setOptions([
                    'responsive' => true, 'maintainAspectRatio' => false, 'cutout' => '76%',
                    'animation' => false, 'events' => [],
                    'plugins' => ['legend' => ['display' => false], 'tooltip' => ['enabled' => false]],
                ]);
                $card['chart'] = $chart;
            }
            if ($card['archived'] || null === $card['section']) {
                $archives[] = $card;
            } else {
                $id = $card['section']['id'];
                $sections[$id] ??= ['id' => $id, 'name' => $card['section']['name'], 'cards' => []];
                $sections[$id]['cards'][] = $card;
            }
        }

        return $this->render('quiz_results/index.html.twig', ['sections' => $sections, 'archives' => $archives],
            new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

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
