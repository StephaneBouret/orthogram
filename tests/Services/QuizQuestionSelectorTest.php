<?php

namespace App\Tests\Services;

use App\Entity\Courses;
use App\Entity\Quiz;
use App\Entity\QuizAnswer;
use App\Entity\QuizAttempt;
use App\Entity\QuizQuestion;
use App\Entity\User;
use App\Exception\InvalidQuizConfiguration;
use App\Services\QuizConfigurationValidator;
use App\Services\QuizQuestionSelector;
use App\Tests\Support\QuizFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class QuizQuestionSelectorTest extends TestCase
{
    private function selector(): QuizQuestionSelector
    {
        return new QuizQuestionSelector(new QuizConfigurationValidator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()));
    }

    private function question(Quiz $quiz, int $id, string $theme = 'A', bool $reserve = false, ?bool $error = null): QuizQuestion
    {
        $q = QuizFactory::question()->setQuiz($quiz)->setPosition($id)->setTheme($theme)->setReserve($reserve)->setContainsError($error);
        (new \ReflectionProperty(QuizQuestion::class, 'id'))->setValue($q, $id);
        foreach ($q->getAnswers() as $index => $answer) {
            (new \ReflectionProperty(QuizAnswer::class, 'id'))->setValue($answer, 100 * $id + $index);
        }

        return $q;
    }

    /** @param list<QuizQuestion> $questions */
    private function previous(Quiz $quiz, array $questions): QuizAttempt
    {
        // Same v1 shape as before migration: deliberately no role/category metadata.
        $frozen = array_map(static fn ($q) => ['id' => $q->getId(), 'title' => $q->getTitle(), 'text' => $q->getText(),
            'multiple' => false, 'theme' => $q->getTheme(), 'explanation' => $q->getExplanation(), 'answers' => []], $questions);

        return new QuizAttempt(new User(), new Courses(), $quiz, ['version' => 1, 'title' => 'Ancien quiz', 'questions' => $frozen]);
    }

    public function testDefaultsFixedOrderAndReserveExclusionEvenWhenReserveIsUnusable(): void
    {
        $quiz = (new Quiz())->setTitle('Fixe');
        $last = $this->question($quiz, 2)->setTheme(null);
        $first = $this->question($quiz, 1)->setTheme(null);
        $this->question($quiz, 3, reserve: true)->setTitle('')->setTheme(null);
        self::assertFalse($quiz->isRenewalEnabled());
        self::assertFalse($first->isReserve());
        self::assertNull($quiz->getErrorQuestionQuota());
        self::assertSame([$first, $last], $this->selector()->select($quiz));
        self::assertSame([$first, $last], $this->selector()->select($quiz, $this->previous($quiz, [$first, $last])));
    }

    public function testFirstPassAndRetriesKeepThemeSlotsAndReuseOnlyWhenNecessary(): void
    {
        $quiz = (new Quiz())->setTitle('Renouvelé')->setRenewalEnabled(true);
        $a = $this->question($quiz, 1);
        $b = $this->question($quiz, 2, 'B');
        $c = $this->question($quiz, 3);
        $reserve = $this->question($quiz, 4, reserve: true);
        $this->question((new Quiz())->setTitle('Étranger'), 5, reserve: true);
        self::assertSame([$a, $b, $c], $this->selector()->select($quiz));
        $selected = $this->selector()->select($quiz, $this->previous($quiz, [$a, $b, $c]));
        self::assertSame([$reserve, $b, $a], $selected);
        self::assertSame([$c, $b, $a], $this->selector()->select($quiz, $this->previous($quiz, $selected)));
        self::assertCount(3, array_unique(array_map(static fn ($q) => $q->getId(), $selected)));
    }

    public function testTwentyQuestionsNineteenThemesAndGlobalTenTenWithoutPerThemeBalance(): void
    {
        $quiz = (new Quiz())->setTitle('Bilan')->setRenewalEnabled(true)->setErrorQuestionQuota(10);
        $initial = $reserves = [];
        for ($i = 0; $i < 20; ++$i) {
            $theme = 'Thème '.max(0, $i - 1);
            $initial[] = $this->question($quiz, $i + 1, $theme, error: $i < 10);
            $reserves[] = $this->question($quiz, $i + 21, $theme, true, $i >= 10);
        }
        self::assertSame($initial, $this->selector()->select($quiz));
        $selected = $this->selector()->select($quiz, $this->previous($quiz, $initial));
        self::assertSame($reserves, $selected);
        self::assertCount(20, $selected);
        self::assertCount(10, array_filter($selected, static fn ($q) => $q->getContainsError()));
        $counts = array_count_values(array_map(static fn ($q) => $q->getTheme(), $selected));
        self::assertCount(19, $counts);
        self::assertSame(2, $counts['Thème 0']);
        self::assertFalse($selected[0]->getContainsError());
        self::assertFalse($selected[1]->getContainsError());
    }

    public function testBalanceOverridesRenewalAndDynamicProgrammingFindsGlobalMinimum(): void
    {
        $quiz = (new Quiz())->setTitle('Contraint')->setRenewalEnabled(true)->setErrorQuestionQuota(1);
        $a = $this->question($quiz, 1, 'A', error: true);
        $b = $this->question($quiz, 2, 'B', error: false);
        $c = $this->question($quiz, 3, 'C', error: false);
        $this->question($quiz, 4, 'A', true, false);
        $this->question($quiz, 5, 'B', true, false);
        $this->question($quiz, 6, 'C', true, false);
        $selected = $this->selector()->select($quiz, $this->previous($quiz, [$a, $b, $c]));
        self::assertSame([1, 5, 6], array_map(static fn ($q) => $q->getId(), $selected));
        $this->question($quiz, 7, 'B', true, true);
        // Brute force oracle for all 2^7 previous-ID subsets, no random assertions.
        $pool = $quiz->getQuestions()->toArray();
        for ($mask = 0; $mask < 128; ++$mask) {
            $previous = array_values(array_filter($pool, static fn ($q) => (bool) ($mask & (1 << ($q->getId() - 1)))));
            $ids = array_map(static fn ($q) => $q->getId(), $previous);
            $best = 3;
            foreach ([$pool[0], $pool[3]] as $qa) {
                foreach ([$pool[1], $pool[4], $pool[6]] as $qb) {
                    foreach ([$pool[2], $pool[5]] as $qc) {
                        if (1 === (int) $qa->getContainsError() + (int) $qb->getContainsError() + (int) $qc->getContainsError()) {
                            $best = min($best, count(array_intersect($ids, [$qa->getId(), $qb->getId(), $qc->getId()])));
                        }
                    }
                }
            }
            $selected = $this->selector()->select($quiz, $this->previous($quiz, $previous));
            self::assertSame($best, count(array_intersect($ids, array_map(static fn ($q) => $q->getId(), $selected))), 'Previous mask '.$mask);
            self::assertSame(['A', 'B', 'C'], array_map(static fn ($q) => $q->getTheme(), $selected));
            self::assertCount(1, array_filter($selected, static fn ($q) => $q->getContainsError()));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'empty' => ['empty', 'question initiale'];
        yield 'theme' => ['theme', 'thème'];
        yield 'unknown theme' => ['unknown', 'thème'];
        yield 'category' => ['category', 'contient une erreur'];
        yield 'impossible' => ['impossible', 'Composition impossible'];
        yield 'range' => ['range', 'compris entre'];
        yield 'answers' => ['answers', 'bonne réponse'];
        yield 'blank answer' => ['blank', 'contenu'];
        yield 'foreign' => ['foreign', 'rattachement'];
        yield 'unpersisted' => ['unpersisted', 'enregistrées'];
        yield 'duplicate ID' => ['duplicate', 'identifiants distincts'];
    }

    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationFailsWithBusinessError(string $kind, string $message): void
    {
        $quiz = (new Quiz())->setTitle('Invalide')->setRenewalEnabled(true);
        $q = $this->question($quiz, 1, error: false);
        $reserve = $this->question($quiz, 2, reserve: true, error: true);
        match ($kind) {
            'empty' => $q->setReserve(true),
            'theme' => $q->setTheme('  '),
            'unknown' => $reserve->setTheme('Autre'),
            'category' => $quiz->setErrorQuestionQuota(0)->getQuestions()->first()->setContainsError(null),
            'impossible' => $quiz->setErrorQuestionQuota(1),
            'range' => $quiz->setErrorQuestionQuota(-1),
            'answers' => $reserve->getAnswers()->first()->setCorrect(false),
            'blank' => $reserve->getAnswers()->first()->setContent(' '),
            'foreign' => $quiz->getQuestions()->add(QuizFactory::question()),
            'unpersisted' => (new \ReflectionProperty(QuizQuestion::class, 'id'))->setValue($q, null),
            'duplicate' => (new \ReflectionProperty(QuizQuestion::class, 'id'))->setValue($reserve, 1),
            default => throw new \LogicException('Cas de test inconnu.'),
        };
        $this->expectException(InvalidQuizConfiguration::class);
        $this->expectExceptionMessage($message);
        $this->selector()->select($quiz);
    }

    public function testEditorialValidationCanRunBeforePersistence(): void
    {
        $q = QuizFactory::question();
        $quiz = $q->getQuiz()->setRenewalEnabled(true);
        $validator = new QuizConfigurationValidator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
        $validator->validate($quiz);
        self::assertNull($q->getId());
        self::assertCount(1, $quiz->getInitialQuestions());
    }
}
