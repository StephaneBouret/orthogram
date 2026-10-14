<?php

declare(strict_types=1);

namespace App\Tests\Services\Dictation;

use App\Services\Dictation\DictationCorrector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OmissionAnchoringTest extends TestCase
{
    /** @return iterable<string, array{string, string, list<int>, list<int>, float}> */
    public static function cases(): iterable
    {
        yield 'final word before period' => ['Le chat dort.', 'Le chat.', [7], [], 66.7];
        yield 'missing comma' => ['Le chat, dort.', 'Le chat dort.', [], [7], 100.0];
        yield 'no final punctuation' => ['Le chat dort', 'Le chat', [7], [], 66.7];
        yield 'period and closing quote' => ['« Le chat dort. »', '« Le chat. »', [9], [], 66.7];
        yield 'trailing spaces' => ['Le chat dort.', 'Le chat.  ', [7], [], 66.7];
        yield 'trailing nonbreaking spaces' => ['Le chat dort.', "Le chat.\u{00A0}\u{00A0}", [7], [], 66.7];
        yield 'trailing spaces without period' => ['Le chat dort', 'Le chat  ', [7], [], 66.7];
        yield 'next sentence' => ['Le chat. Demain', 'Le chat.', [8], [], 66.7];
        yield 'next sentence trailing spaces' => ['Le chat. Demain', "Le chat. \u{00A0}", [8], [], 66.7];
        yield 'after closing quote' => ['« Le chat. » Demain', '« Le chat. » ', [12], [], 66.7];
        yield 'non BMP before omission' => ['😀 Le chat dort.', '😀 Le chat.', [10], [], 66.7];
        yield 'decomposed accent before omission' => ['Le bébé dort.', "Le be\u{0301}be\u{0301}.", [9], [], 66.7];
        yield 'initial lexical omission' => ['petit chat', 'chat', [0], [], 50.0];
        yield 'middle lexical omission' => ['Le petit chat dort.', 'Le chat dort.', [3], [], 75.0];
        yield 'two words at same anchor' => ['Le chat dort bien.', 'Le chat.', [7, 7], [], 50.0];
        yield 'two words across a retained period' => ['Le chat dort. Demain', 'Le chat.', [7, 8], [], 50.0];
        yield 'empty copy' => ['Le chat.', '', [0, 0], [0], 0.0];
        yield 'spaces only copy' => ['Le chat.', '  ', [0, 0], [0], 0.0];
        yield 'period only copy' => ['Le chat.', '.', [0, 0], [], 0.0];
        yield 'punctuation only copy' => ['« Le chat. »', '« . »', [1, 1], [], 0.0];
        yield 'initial punctuation omission' => ['« Le chat.', 'Le chat.', [], [0], 100.0];
        yield 'initial punctuation before retained sign' => ['« ( chat', '( chat', [], [0], 100.0];
        yield 'initial punctuation with indentation' => ['« chat', '  chat', [], [2], 100.0];
        yield 'final punctuation omission' => ['Le chat.', 'Le chat', [], [7], 100.0];
        yield 'final punctuation before trailing spaces' => ['Le chat.', "Le chat \u{00A0}", [], [7], 100.0];
        yield 'missing sign after retained period' => ['Le chat. »', 'Le chat.  ', [], [8], 100.0];
        yield 'missing sign before retained quote' => ['Le chat, »', 'Le chat »', [], [7], 100.0];
        yield 'repeated punctuation prefers prefix' => ['Le chat. Demain.', 'Le chat.', [8], [8], 66.7];
        yield 'retained suffix after omitted word' => ['Le chat! Demain.', 'Le chat.', [7], [7], 66.7];
    }

    /**
     * @param list<int> $lexicalPoints
     * @param list<int> $punctuationPoints
     */
    #[DataProvider('cases')]
    public function testEditingAnchorsPreserveCountsAndOriginals(string $reference, string $copy, array $lexicalPoints, array $punctuationPoints, float $score): void
    {
        $result = (new DictationCorrector())->correct($reference, $copy);
        $feedback = $result->publicFeedback();
        $expected = [];
        foreach (['lexical' => $lexicalPoints, 'punctuation' => $punctuationPoints] as $domain => $points) {
            foreach ($points as $point) {
                $expected[] = ['domain' => $domain, 'type' => 'omission', 'start' => $point, 'end' => $point];
            }
        }
        usort($expected, static fn (array $a, array $b): int => [$a['start'], $a['domain']] <=> [$b['start'], $b['domain']]);
        self::assertSame($expected, $feedback['issues']);
        self::assertSame(count($lexicalPoints), $feedback['lexicalErrorCount']);
        self::assertSame(count($punctuationPoints), $feedback['punctuationErrorCount']);
        self::assertSame($score, $feedback['score']);
        self::assertFalse($feedback['noErrors']);
        self::assertSame($reference, $result->referenceOriginal);
        self::assertSame($copy, $result->copyOriginal);
    }

    public function testExistingWordAndPunctuationSpansRemainUnchanged(): void
    {
        $result = (new DictationCorrector())->correct('Le CHAT dort mange.', 'Le chat dort!');
        self::assertSame([
            ['domain' => 'lexical', 'type' => 'substitution', 'start' => 3, 'end' => 7],
            ['domain' => 'lexical', 'type' => 'omission', 'start' => 12, 'end' => 12],
            ['domain' => 'punctuation', 'type' => 'substitution', 'start' => 12, 'end' => 13],
        ], $result->publicFeedback()['issues']);
        self::assertSame(2, $result->lexicalErrorCount);
        self::assertSame(1, $result->punctuationErrorCount);
        self::assertSame(50.0, $result->score());
        self::assertFalse($result->noErrors());
    }
}
