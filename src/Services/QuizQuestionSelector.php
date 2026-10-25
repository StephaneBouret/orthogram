<?php

namespace App\Services;

use App\Entity\Quiz;
use App\Entity\QuizAttempt;
use App\Entity\QuizQuestion;
use App\Exception\InvalidQuizConfiguration;

final class QuizQuestionSelector
{
    public function __construct(private readonly QuizConfigurationValidator $configuration)
    {
    }

    /**
     * Deterministic minimum reuse, with editorial position/ID as tie breakers.
     * Only previous snapshot IDs matter; old snapshots need no new metadata.
     *
     * @return list<QuizQuestion>
     */
    public function select(Quiz $quiz, ?QuizAttempt $previous = null): array
    {
        $this->configuration->validate($quiz, requirePersisted: true);
        $initial = $quiz->getInitialQuestions();
        if (!$quiz->isRenewalEnabled() || null === $previous) {
            return $initial;
        }
        $used = array_fill_keys(array_column($previous->getSnapshot()['questions'], 'id'), true);
        $quotas = [];
        foreach ($initial as $question) {
            $theme = trim($question->getTheme() ?? '');
            $quotas[$theme] = ($quotas[$theme] ?? 0) + 1;
        }
        $pool = $quiz->getQuestions()->toArray();
        usort($pool, static fn (QuizQuestion $a, QuizQuestion $b) => [isset($used[$a->getId()]), $a->getPosition(), $a->getId()] <=> [isset($used[$b->getId()]), $b->getPosition(), $b->getId()]);
        $target = $quiz->getErrorQuestionQuota();
        // DP state: global number of erroneous sentences -> cheapest composition.
        // For a given theme/error count, taking unused questions first is optimal.
        $states = [0 => ['cost' => 0, 'questions' => []]];
        foreach ($quotas as $theme => $count) {
            $candidates = array_values(array_filter($pool, static fn ($q) => trim($q->getTheme() ?? '') === (string) $theme));
            $options = [];
            if (null === $target) {
                $options[0] = array_slice($candidates, 0, $count);
            } else {
                $errors = array_values(array_filter($candidates, static fn ($q) => true === $q->getContainsError()));
                $correct = array_values(array_filter($candidates, static fn ($q) => false === $q->getContainsError()));
                for ($n = max(0, $count - count($correct)); $n <= min($count, count($errors), $target); ++$n) {
                    $options[$n] = array_merge(array_slice($errors, 0, $n), array_slice($correct, 0, $count - $n));
                }
            }
            $next = [];
            foreach ($states as $total => $state) {
                foreach ($options as $errors => $questions) {
                    $sum = $total + $errors;
                    if (null !== $target && $sum > $target) {
                        continue;
                    }
                    $cost = $state['cost'] + count(array_filter($questions, static fn ($q) => isset($used[$q->getId()])));
                    if (!isset($next[$sum]) || $cost < $next[$sum]['cost']) {
                        $next[$sum] = ['cost' => $cost, 'questions' => array_merge($state['questions'], $questions)];
                    }
                }
            }
            $states = $next;
        }
        if (!isset($states[$target ?? 0])) {
            throw new InvalidQuizConfiguration('Composition impossible avec les quotas par thème et l’équilibre global demandés.');
        }
        // Keep the initial theme slots, without imposing a per-theme error balance.
        $selected = [];
        foreach ($states[$target ?? 0]['questions'] as $question) {
            $selected[trim($question->getTheme() ?? '')][] = $question;
        }
        $result = [];
        foreach ($initial as $slot) {
            $result[] = array_shift($selected[trim($slot->getTheme() ?? '')]);
        }

        return $result;
    }
}
