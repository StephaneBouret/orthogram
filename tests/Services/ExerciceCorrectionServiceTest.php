<?php

namespace App\Tests\Services;

use App\Entity\Exercice;
use App\Services\ExerciceCorrectionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExerciceCorrectionServiceTest extends TestCase
{
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
        yield 'no selection' => [[], 1, ['s4__none' => 'correct', 's5_w1' => 'missed']];
        yield 'wrong word' => [['s4_w1'], 0, ['s4_w1' => 'wrong', 's4__none' => 'missed', 's5_w1' => 'missed']];
        yield 'several wrong words' => [['s4_w1', 's4_w2'], 0, ['s4_w1' => 'wrong', 's4_w2' => 'wrong', 's4__none' => 'missed', 's5_w1' => 'missed']];
        yield 'virtual ID cannot override a wrong word' => [['s4__none', 's4_w1'], 0, ['s4_w1' => 'wrong', 's4__none' => 'missed', 's5_w1' => 'missed']];
    }

    public function testClassicExerciseRemainsUnchanged(): void
    {
        $exercice = (new Exercice())->setSentences([
            ['id' => 's1', 'noAnswer' => false, 'words' => [
                ['id' => 'a', 'text' => 'pain', 'isAnswer' => true],
                ['id' => 'b', 'text' => 'riz', 'isAnswer' => true],
                ['id' => 'c', 'text' => 'le'],
            ]],
            ['id' => 's2', 'words' => [['id' => 'd', 'text' => 'vite']]],
        ]);
        $result = (new ExerciceCorrectionService())->correctClickWords($exercice, ['a', 'a', 'c']);
        self::assertSame(1, $result['score']);
        self::assertSame(2, $result['total']);
        self::assertSame(50, $result['percentage']);
        self::assertSame(['a' => 'correct', 'c' => 'wrong', 'b' => 'missed'], array_column($result['items'], 'status', 'tokenId'));
        self::assertSame(['a' => 's1', 'c' => 's1', 'b' => 's1'], array_column($result['items'], 'sentenceId', 'tokenId'));
    }

    public function testNoAnswerHasGenericFallback(): void
    {
        $exercice = (new Exercice())->setSentences([['noAnswer' => true, 'words' => []]]);
        $result = (new ExerciceCorrectionService())->correctClickWords($exercice, []);
        self::assertSame(1, $result['score']);
        self::assertSame(1, $result['total']);
        self::assertSame('Aucune réponse attendue dans cette phrase.', $result['items'][0]['explanation']);
        self::assertSame('s1', $result['items'][0]['sentenceId']);
    }

    public function testUnknownTokenHasNoInventedSentence(): void
    {
        $result = (new ExerciceCorrectionService())->correctClickWords(new Exercice(), ['unknown']);
        self::assertSame(0, $result['score']);
        self::assertSame(0, $result['total']);
        self::assertSame('wrong', $result['items'][0]['status']);
        self::assertNull($result['items'][0]['sentenceId']);
    }
}
