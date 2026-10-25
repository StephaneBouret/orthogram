<?php

namespace App\Services;

use App\Entity\Quiz;
use App\Exception\InvalidQuizConfiguration;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Shared by attempt creation and future editorial activation/forms. Never writes. */
final class QuizConfigurationValidator
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    public function validate(Quiz $quiz, bool $requirePersisted = false): void
    {
        $initial = $quiz->getInitialQuestions();
        if ([] === $initial) {
            throw new InvalidQuizConfiguration('Le quiz doit contenir au moins une question initiale.');
        }
        $violations = $this->validator->validateProperty($quiz, 'title');
        if (count($violations) > 0) {
            throw new InvalidQuizConfiguration((string) $violations[0]->getMessage());
        }
        $themes = [];
        foreach ($initial as $question) {
            $themes[trim($question->getTheme() ?? '')] = true;
        }
        $quota = $quiz->getErrorQuestionQuota();
        if (null !== $quota && ($quota < 0 || $quota > count($initial))) {
            throw new InvalidQuizConfiguration('Le quota de phrases erronées doit être compris entre zéro et le nombre de questions initiales.');
        }
        $questions = $quiz->isRenewalEnabled() ? $quiz->getQuestions()->toArray() : $initial;
        $questionIds = [];
        $answerIds = [];
        foreach ($questions as $question) {
            $label = 'Question '.($question->getId() ?? $question->getTitle()).' : ';
            if ($question->getQuiz() !== $quiz) {
                throw new InvalidQuizConfiguration($label.'le rattachement au quiz est incohérent.');
            }
            $violations = $this->validator->validate($question);
            if (count($violations) > 0) {
                throw new InvalidQuizConfiguration($label.(string) $violations[0]->getMessage());
            }
            if ($quiz->isRenewalEnabled()) {
                $theme = trim($question->getTheme() ?? '');
                if ('' === $theme || !isset($themes[$theme])) {
                    throw new InvalidQuizConfiguration($label.'un thème du jeu initial est obligatoire pour le renouvellement.');
                }
            }
            if (null !== $quota && null === $question->getContainsError()) {
                throw new InvalidQuizConfiguration($label.'précisez explicitement si la phrase contient une erreur.');
            }
            if ($requirePersisted) {
                $id = $question->getId();
                if (null === $id || $id <= 0 || isset($questionIds[$id])) {
                    throw new InvalidQuizConfiguration($label.'les questions doivent être enregistrées avec des identifiants distincts.');
                }
                $questionIds[$id] = true;
                foreach ($question->getAnswers() as $answer) {
                    $id = $answer->getId();
                    if (null === $id || $id <= 0 || isset($answerIds[$id])) {
                        throw new InvalidQuizConfiguration($label.'les propositions doivent être enregistrées avec des identifiants distincts.');
                    }
                    $answerIds[$id] = true;
                }
            }
        }
        // The mandatory first passage must satisfy the balance too. Once it does,
        // it also proves feasibility for every retry: initial questions stay eligible.
        if (null !== $quota && $quota !== count(array_filter($initial, static fn ($q) => true === $q->getContainsError()))) {
            throw new InvalidQuizConfiguration('Composition impossible : le jeu initial ne respecte pas le quota de phrases erronées.');
        }
    }
}
