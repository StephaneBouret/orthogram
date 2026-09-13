<?php

namespace App\Tests\Services;

use App\Services\QuizCorrectionService;
use App\Tests\Support\QuizFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class QuizCorrectionServiceTest extends TestCase
{
    private function service(): QuizCorrectionService
    {
        return new QuizCorrectionService(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
    }

    /** @return iterable<string, array{bool, list<bool>, list<int>, bool}> */
    public static function corrections(): iterable
    {
        yield 'unique juste' => [false, [true, false], [1], true];
        yield 'unique fausse' => [false, [true, false], [2], false];
        yield 'multiple exacte' => [true, [true, true, false], [1, 2], true];
        yield 'ordre inversé' => [true, [true, true, false], [2, 1], true];
        yield 'partielle' => [true, [true, true, false], [1], false];
        yield 'fausse ajoutée' => [true, [true, true, false], [1, 2, 3], false];
        yield 'multiple une bonne réponse' => [true, [true, false], [1], true];
    }

    /** @param list<bool> $correctAnswers
     * @param list<int> $selected
     */
    #[DataProvider('corrections')]
    public function testCorrection(bool $multiple, array $correctAnswers, array $selected, bool $expected): void
    {
        self::assertSame($expected, $this->service()->correct(QuizFactory::question($multiple, $correctAnswers, true), $selected));
    }

    /** @return iterable<string, array{array<array-key, mixed>, bool}> */
    public static function invalidSelections(): iterable
    {
        yield 'vide' => [[], true];
        yield 'doublons' => [[1, 1], true];
        yield 'étranger' => [[99], true];
        yield 'zéro' => [[0], true];
        yield 'négatif' => [[-1], true];
        yield 'chaîne numérique' => [['1'], true];
        yield 'flottant' => [[1.0], true];
        yield 'booléen' => [[true], true];
        yield 'null' => [[null], true];
        yield 'tableau imbriqué' => [[[1]], true];
        yield 'objet' => [[new \stdClass()], true];
        yield 'tableau associatif' => [['id' => 1], true];
        yield 'index non consécutifs' => [[1 => 1], true];
        yield 'plusieurs en mode unique' => [[1, 2], false];
    }

    /** @param array<array-key, mixed> $selected */
    #[DataProvider('invalidSelections')]
    public function testInvalidSelection(array $selected, bool $multiple): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->correct(QuizFactory::question($multiple, [true, false], true), $selected);
    }

    public function testEmptyMisconfiguredQuestionCannotSucceed(): void
    {
        $this->expectException(\LogicException::class);
        $this->service()->correct(QuizFactory::question(true, [], true), []);
    }

    public function testSingleQuestionWithTwoCorrectAnswersIsMisconfigured(): void
    {
        $this->expectException(\LogicException::class);
        $this->service()->correct(QuizFactory::question(false, [true, true], true), [1]);
    }

    public function testUnpersistedAnswersCannotBeCorrected(): void
    {
        $this->expectException(\LogicException::class);
        $this->service()->correct(QuizFactory::question(), [1]);
    }
}
