<?php

namespace App\Tests\Entity;

use App\Entity\Courses;
use App\Entity\Quiz;
use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;
use App\Enum\CourseContentType;
use App\Tests\Support\QuizFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class QuizQuestionTest extends TestCase
{
    /** @return iterable<string, array{bool, list<bool>, bool}> */
    public static function configurations(): iterable
    {
        yield 'unique sans bonne réponse' => [false, [false, false], false];
        yield 'unique une bonne réponse' => [false, [true, false], true];
        yield 'unique plusieurs bonnes réponses' => [false, [true, true], false];
        yield 'multiple sans bonne réponse' => [true, [false, false], false];
        yield 'multiple une bonne réponse' => [true, [true, false], true];
        yield 'multiple plusieurs bonnes réponses' => [true, [true, true], true];
        yield 'aucune proposition' => [false, [], false];
        yield 'une seule proposition' => [false, [true], false];
    }

    /** @param list<bool> $correctAnswers */
    #[DataProvider('configurations')]
    public function testConfiguration(bool $multiple, array $correctAnswers, bool $valid): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $question = QuizFactory::question($multiple, $correctAnswers);
        self::assertSame($valid, 0 === count($validator->validate($question)));
    }

    public function testBlankFieldsNegativePositionsAndMissingParentAreRejected(): void
    {
        $question = QuizFactory::question()->setTitle('  ')->setPosition(-1)->setQuiz(null);
        $question->getAnswers()->first()->setContent('  ')->setPosition(-1);
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $paths = [];
        foreach ($validator->validate($question) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }
        self::assertEqualsCanonicalizing(['quiz', 'title', 'position', 'answers[0].content', 'answers[0].position'], $paths);
        self::assertCount(1, $validator->validate((new Quiz())->setTitle(' ')));
        self::assertCount(0, $validator->validate((new Quiz())->setTitle('Quiz vide autorisé')));
    }

    public function testBidirectionalRelationsAndReparenting(): void
    {
        $question = QuizFactory::question();
        $oldQuiz = $question->getQuiz();
        $newQuiz = (new Quiz())->setTitle('Autre quiz');
        $newQuiz->addQuestion($question);
        self::assertFalse($oldQuiz->getQuestions()->contains($question));
        self::assertSame($newQuiz, $question->getQuiz());

        $answer = $question->getAnswers()->first();
        $other = (new QuizQuestion())->setQuiz($newQuiz);
        $answer->setQuestion($other);
        self::assertFalse($question->getAnswers()->contains($answer));
        self::assertTrue($other->getAnswers()->contains($answer));
        $other->removeAnswer($answer);
        self::assertNull($answer->getQuestion());
        $newQuiz->removeQuestion($other);
        self::assertNull($other->getQuiz());

        $course = (new Courses())->setQuiz($oldQuiz);
        $newQuiz->addCourse($course);
        self::assertFalse($oldQuiz->getCourses()->contains($course));
        self::assertSame($newQuiz, $course->getQuiz());
        $newQuiz->removeCourse($course);
        self::assertNull($course->getQuiz());
    }

    public function testInconsistentAnswerParentIsRejected(): void
    {
        $question = QuizFactory::question();
        $question->getAnswers()->add((new QuizAnswer())->setContent('Intruse')->setQuestion(new QuizQuestion()));
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        self::assertGreaterThan(0, count($validator->validate($question)));
    }

    public function testQuizCourseRequiresQuizButCanReferenceAnEmptyQuiz(): void
    {
        $course = (new Courses())->setName('Quiz de révision')->setContentType(CourseContentType::Quiz);
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        self::assertSame('quiz', $validator->validate($course)->get(0)->getPropertyPath());
        $course->setQuiz((new Quiz())->setTitle('À préparer'));
        self::assertCount(0, $validator->validate($course));
    }
}
