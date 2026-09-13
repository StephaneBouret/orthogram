<?php

namespace App\Tests\Support;

use App\Entity\Quiz;
use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;

final class QuizFactory
{
    /** @param list<bool> $correctAnswers */
    public static function question(bool $multiple = false, array $correctAnswers = [true, false], bool $withIds = false): QuizQuestion
    {
        $question = (new QuizQuestion())->setQuiz((new Quiz())->setTitle('Quiz test'))
            ->setTitle('Repérez les mots demandés.')->setText('Le chat dort tranquillement.')
            ->setExplanation('Explication de la réponse.')->setMultiple($multiple)->setTheme('Thème libre');

        foreach ($correctAnswers as $index => $correct) {
            $answer = (new QuizAnswer())->setContent('Proposition '.($index + 1))->setCorrect($correct)->setPosition($index);
            if ($withIds) {
                (new \ReflectionProperty(QuizAnswer::class, 'id'))->setValue($answer, $index + 1);
            }
            $question->addAnswer($answer);
        }

        return $question;
    }
}
