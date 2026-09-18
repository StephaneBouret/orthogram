<?php

namespace App\Controller;

use App\Services\QuizResultsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
            $card['chart'] = $this->doughnut($chartBuilder, $latest);
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

    #[Route('/mes-resultats/historique/{anchor}', name: 'app_quiz_results_history', requirements: ['anchor' => '\d+'], methods: ['GET'])]
    public function history(string $anchor, Request $request, QuizResultsService $results, ChartBuilderInterface $chartBuilder): Response
    {
        $query = $request->query->all();
        $selectedId = array_key_exists('attempt', $query) ? $this->positiveId($query['attempt']) : null;
        $group = $results->history($this->positiveId($anchor), $selectedId);

        return $this->render('quiz_results/history.html.twig', [
            'group' => $group, 'selected' => $group['selected'],
            'evolution' => $this->evolution($chartBuilder, $group['history'], $group['selected']['attemptId']),
            'ring' => $this->doughnut($chartBuilder, $group['selected']),
        ], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    private function positiveId(mixed $value): int
    {
        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1 || (string) (int) $value !== $value) {
            throw new NotFoundHttpException('Tentative introuvable.', headers: ['Cache-Control' => 'private, no-store']);
        }

        return (int) $value;
    }

    /** @param array<string, mixed>|null $result */
    private function doughnut(ChartBuilderInterface $builder, ?array $result): ?Chart
    {
        if (null === $result || $result['total'] <= 0 || $result['score'] < 0 || $result['score'] > $result['total']) {
            return null;
        }
        $chart = $builder->createChart(Chart::TYPE_DOUGHNUT);
        $chart->setData([
            'labels' => ['Réponses correctes', 'Réponses incorrectes'],
            'datasets' => [['data' => [$result['score'], $result['total'] - $result['score']], 'borderWidth' => 0]],
        ]);
        $chart->setOptions([
            'responsive' => true, 'maintainAspectRatio' => false, 'cutout' => '76%',
            'animation' => false, 'events' => [],
            'plugins' => ['legend' => ['display' => false], 'tooltip' => ['enabled' => false]],
        ]);

        return $chart;
    }

    /** @param list<array<string, mixed>> $history */
    private function evolution(ChartBuilderInterface $builder, array $history, int $selectedId): Chart
    {
        $labels = [];
        $datasets = [];
        $run = -1;
        foreach ($history as $index => $row) {
            if (0 === $index || $row['contentChanged']) {
                ++$run;
                $datasets[$run] = ['data' => [], 'pointRadius' => [], 'tension' => 0, 'spanGaps' => false, 'fill' => false, 'borderWidth' => 2];
            }
            // Category labels are unique even for attempts on the same day.
            $date = $row['completedAt']->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            $label = '#'.($index + 1).' · '.$date->format('d/m');
            $labels[] = $label;
            $datasets[$run]['data'][] = ['x' => $label, 'y' => $row['percentage'], 'number' => $index + 1,
                'date' => $date->format('d/m/Y H:i'), 'score' => $row['score'], 'total' => $row['total']];
            $datasets[$run]['pointRadius'][] = $selectedId === $row['attemptId'] ? 7 : 4;
        }
        $chart = $builder->createChart(Chart::TYPE_LINE);
        $chart->setData(['labels' => $labels, 'datasets' => $datasets]);
        $chart->setOptions([
            'responsive' => true, 'maintainAspectRatio' => false, 'animation' => false,
            'scales' => [
                'y' => ['min' => 0, 'max' => 100, 'ticks' => ['stepSize' => 20], 'title' => ['display' => true, 'text' => 'Résultat (%)']],
                'x' => ['type' => 'category', 'offset' => true, 'title' => ['display' => true, 'text' => 'Tentatives successives']],
            ],
            'plugins' => ['legend' => ['display' => false]],
        ]);

        return $chart;
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
