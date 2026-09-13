<?php

namespace App\Services;

use App\Entity\QuizQuestion;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class QuizCorrectionService
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    /**
     * @param array<array-key, mixed> $selectedAnswerIds untrusted input: must be a list of positive integers
     *
     * @throws \InvalidArgumentException invalid selection, distinct from an incorrect answer (false)
     * @throws \LogicException           invalid or unpersisted question configuration
     */
    public function correct(QuizQuestion $question, array $selectedAnswerIds): bool
    {
        if (count($this->validator->validate($question)) > 0) {
            throw new \LogicException('La question est mal configurée.');
        }

        $answerIds = [];
        $correctIds = [];
        foreach ($question->getAnswers() as $answer) {
            $id = $answer->getId();
            if (null === $id || $id <= 0 || in_array($id, $answerIds, true)) {
                throw new \LogicException('Les propositions doivent avoir des identifiants persistés et distincts.');
            }
            $answerIds[] = $id;
            if ($answer->isCorrect()) {
                $correctIds[] = $id;
            }
        }

        return $this->correctSelection((bool) $question->isMultiple(), $answerIds, $correctIds, $selectedAnswerIds);
    }

    /**
     * Shared rule for validated entities and private, server-created snapshots.
     *
     * @param list<int>               $answerIds
     * @param list<int>               $correctIds
     * @param array<array-key, mixed> $selectedAnswerIds
     */
    public function correctSelection(bool $multiple, array $answerIds, array $correctIds, array $selectedAnswerIds): bool
    {
        if (!array_is_list($selectedAnswerIds) || [] === $selectedAnswerIds) {
            throw new \InvalidArgumentException('La sélection doit être une liste non vide.');
        }
        if (!$multiple && 1 !== count($selectedAnswerIds)) {
            throw new \InvalidArgumentException('Sélectionnez une seule proposition.');
        }

        $seen = [];
        foreach ($selectedAnswerIds as $id) {
            if (!is_int($id) || $id <= 0 || !in_array($id, $answerIds, true) || in_array($id, $seen, true)) {
                throw new \InvalidArgumentException('Identifiant invalide, étranger à la question ou présent plusieurs fois.');
            }
            $seen[] = $id;
        }

        sort($seen);
        sort($correctIds);

        return $seen === $correctIds;
    }
}
