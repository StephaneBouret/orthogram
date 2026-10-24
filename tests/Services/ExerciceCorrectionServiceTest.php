<?php

namespace App\Tests\Services;

use App\Entity\Exercice;
use App\Services\ExerciceCorrectionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExerciceCorrectionServiceTest extends TestCase
{
    /**
     * @param list<string> $selected
     */
    #[DataProvider('classicScoreCases')]
    public function testClassicScore(array $selected, int $score, int $percentage): void
    {
        $exercice = (new Exercice())->setSentences([
            ['id' => 's1', 'words' => [
                ['id' => 'a', 'text' => 'a', 'isAnswer' => true],
                ['id' => 'b', 'text' => 'à', 'isAnswer' => true],
                ['id' => 'd', 'text' => 'avait', 'isAnswer' => false],
            ]],
            ['id' => 's2', 'words' => [
                ['id' => 'c', 'text' => 'a', 'isAnswer' => true],
                ['id' => 'e', 'text' => 'avait', 'isAnswer' => false],
            ]],
        ]);

        $result = (new ExerciceCorrectionService())->correctClickWords($exercice, $selected);

        self::assertSame($score, $result['score']);
        self::assertSame(3, $result['total']);
        self::assertSame($percentage, $result['percentage']);
        self::assertGreaterThanOrEqual(0, $result['percentage']);
        self::assertLessThanOrEqual(100, $result['percentage']);
    }

    /**
     * @return iterable<string, array{list<string>, int, int}>
     */
    public static function classicScoreCases(): iterable
    {
        yield 'all correct' => [['a', 'b', 'c'], 3, 100];
        yield 'all correct and one wrong' => [['a', 'b', 'c', 'd'], 2, 67];
        yield 'one missed' => [['a', 'b'], 2, 67];
        yield 'one missed and one wrong' => [['a', 'b', 'd'], 1, 33];
        yield 'all correct and several wrong' => [['a', 'b', 'c', 'd', 'e'], 1, 33];
        yield 'zero floor' => [['a', 'd', 'e'], 0, 0];
        yield 'only wrong selections' => [['d', 'e'], 0, 0];
        yield 'no selection' => [[], 0, 0];
        yield 'duplicate correct selections' => [['a', 'a', 'b', 'c', 'c'], 3, 100];
        yield 'duplicate wrong selections' => [['a', 'b', 'c', 'd', 'd'], 2, 67];
        yield 'unknown selection is wrong' => [['a', 'b', 'c', 'unknown'], 2, 67];
    }

    /**
     * @param list<string>          $selected
     * @param array<string, string> $statuses
     */
    #[DataProvider('selectionCases')]
    public function testImplicitNoAnswer(array $selected, int $score, array $statuses): void
    {
        $exercice = (new Exercice())->setSentences([
            ['id' => 's4', 'noAnswer' => true, 'noAnswerExplanation' => 'Aucun nom dans cette phrase.', 'words' => [
                ['id' => 's4_w1', 'text' => 'Ils', 'isAnswer' => false],
                ['id' => 's4_w2', 'text' => 'savaient', 'isAnswer' => false],
            ]],
            ['id' => 's5', 'words' => [['id' => 's5_w1', 'text' => 'pain', 'isAnswer' => true]]],
        ]);

        $result = (new ExerciceCorrectionService())->correctClickWords($exercice, $selected);

        self::assertSame($score, $result['score']);
        self::assertSame(2, $result['total']);
        self::assertSame($score * 50, $result['percentage']);
        self::assertSame($statuses, array_column($result['items'], 'status', 'tokenId'));
        foreach ($result['items'] as $item) {
            self::assertSame('s5_w1' === $item['tokenId'] ? 's5' : 's4', $item['sentenceId']);
        }
        $explanations = array_column($result['items'], 'explanation', 'tokenId');
        self::assertSame('Aucun nom dans cette phrase.', $explanations['s4__none']);
        if (isset($explanations['s4_w1'])) {
            self::assertSame('Cette sélection n’est pas une réponse attendue dans cette phrase.', $explanations['s4_w1']);
        }
    }

    /**
     * @return iterable<string, array{list<string>, int, array<string, string>}>
     */
    public static function selectionCases(): iterable
    {
        yield 'mixed exercise' => [['s5_w1'], 2, ['s4__none' => 'correct', 's5_w1' => 'correct']];
        yield 'explicit virtual ID is not counted twice' => [['s4__none', 's5_w1'], 2, ['s4__none' => 'correct', 's5_w1' => 'correct']];
        yield 'no selection' => [[], 1, ['s4__none' => 'correct', 's5_w1' => 'missed']];
        yield 'correct answer and wrong noAnswer word' => [['s5_w1', 's4_w1'], 0, ['s5_w1' => 'correct', 's4_w1' => 'wrong', 's4__none' => 'missed']];
        yield 'correct answer and several wrong noAnswer words' => [['s5_w1', 's4_w1', 's4_w2'], 0, ['s5_w1' => 'correct', 's4_w1' => 'wrong', 's4_w2' => 'wrong', 's4__none' => 'missed']];
        yield 'wrong word' => [['s4_w1'], 0, ['s4_w1' => 'wrong', 's4__none' => 'missed', 's5_w1' => 'missed']];
        yield 'several wrong words' => [['s4_w1', 's4_w2'], 0, ['s4_w1' => 'wrong', 's4_w2' => 'wrong', 's4__none' => 'missed', 's5_w1' => 'missed']];
        yield 'virtual ID cannot override a wrong word' => [['s4__none', 's4_w1'], 0, ['s4_w1' => 'wrong', 's4__none' => 'missed', 's5_w1' => 'missed']];
        yield 'virtual ID cannot avoid the penalty' => [['s5_w1', 's4__none', 's4_w1'], 0, ['s5_w1' => 'correct', 's4_w1' => 'wrong', 's4__none' => 'missed']];
    }

    public function testClassicCorrectionPreservesItemsAndSentenceIds(): void
    {
        $exercice = (new Exercice())->setSentences([
            ['id' => 's1', 'noAnswer' => false, 'words' => [
                ['id' => 'a', 'text' => 'pain', 'isAnswer' => true, 'explanation' => 'Pain est un nom.'],
                ['id' => 'b', 'text' => 'riz', 'isAnswer' => true],
                ['id' => 'c', 'text' => 'le'],
            ]],
            ['id' => 's2', 'words' => [['id' => 'd', 'text' => 'vite', 'explanation' => 'Vite est un adverbe.']]],
        ]);
        $result = (new ExerciceCorrectionService())->correctClickWords($exercice, ['a', 'a', 'c', 'd']);
        self::assertSame([
            'score' => 0,
            'total' => 2,
            'percentage' => 0,
            'items' => [
                ['tokenId' => 'a', 'sentenceId' => 's1', 'status' => 'correct', 'explanation' => 'Pain est un nom.'],
                ['tokenId' => 'c', 'sentenceId' => 's1', 'status' => 'wrong', 'explanation' => 'Cette sélection n’est pas une réponse attendue dans cette phrase.'],
                ['tokenId' => 'd', 'sentenceId' => 's2', 'status' => 'wrong', 'explanation' => 'Vite est un adverbe.'],
                ['tokenId' => 'b', 'sentenceId' => 's1', 'status' => 'missed', 'explanation' => 'Ce mot était une réponse attendue.'],
            ],
        ], $result);
    }

    public function testNoAnswerHasGenericFallback(): void
    {
        $exercice = (new Exercice())->setSentences([['noAnswer' => true, 'words' => []]]);
        $result = (new ExerciceCorrectionService())->correctClickWords($exercice, []);
        self::assertSame(1, $result['score']);
        self::assertSame(1, $result['total']);
        self::assertSame(100, $result['percentage']);
        self::assertSame('Aucune réponse attendue dans cette phrase.', $result['items'][0]['explanation']);
        self::assertSame('s1', $result['items'][0]['sentenceId']);
    }

    public function testUnknownTokenHasNoInventedSentence(): void
    {
        $result = (new ExerciceCorrectionService())->correctClickWords(new Exercice(), ['unknown']);
        self::assertSame(0, $result['score']);
        self::assertSame(0, $result['total']);
        self::assertSame(0, $result['percentage']);
        self::assertSame('wrong', $result['items'][0]['status']);
        self::assertNull($result['items'][0]['sentenceId']);
    }
}
