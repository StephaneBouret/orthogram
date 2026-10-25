<?php

namespace App\Services;

use App\Entity\Quiz;

final class QuizCompositionSummary
{
    /** @return list<array{theme: string, initial: int, reserve: int, correct: int, error: int, unknown: int}> */
    public function build(Quiz $quiz): array
    {
        $rows = [];
        foreach ($quiz->getQuestions() as $question) {
            $theme = trim($question->getTheme() ?? '');
            $rows[$theme] ??= ['theme' => '' === $theme ? 'Sans thème' : $theme, 'initial' => 0, 'reserve' => 0, 'correct' => 0, 'error' => 0, 'unknown' => 0];
            ++$rows[$theme][$question->isReserve() ? 'reserve' : 'initial'];
            ++$rows[$theme][match ($question->getContainsError()) {
                null => 'unknown',
                false => 'correct',
                true => 'error',
            }];
        }

        return array_values($rows);
    }
}
