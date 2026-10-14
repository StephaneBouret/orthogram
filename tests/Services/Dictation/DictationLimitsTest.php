<?php

declare(strict_types=1);

namespace App\Tests\Services\Dictation;

use App\Services\Dictation\DictationCorrector;
use App\Services\Dictation\DictationTokenizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DictationLimitsTest extends TestCase
{
    /** @return iterable<string, array{string, string, array<mixed>}> */
    public static function invalidInputs(): iterable
    {
        yield 'invalid copy UTF8' => ['secret', "\xC3\x28", []];
        yield 'invalid reference UTF8' => ["secret\xFF", 'copie', []];
        yield 'empty reference' => ['', 'copie', []];
        yield 'punctuation reference' => ['... 😀', 'copie', []];
        yield 'standalone mark reference' => ["\u{0301}", 'copie', []];
        yield 'too many reference words' => [str_repeat('a ', 501), '', []];
        yield 'too many copy words' => ['secret', str_repeat('a ', 751), []];
        yield 'long reference' => [str_repeat('a', 20001), '', []];
        yield 'long copy' => ['secret', str_repeat('a', 20001), []];
        yield 'long copy BMP' => ['secret', str_repeat('漢', 20001), []];
        yield 'long copy non BMP' => ['secret', str_repeat('😀', 10001), []];
        yield 'variants not a list' => ['secret', '', ['passage' => []]];
        yield 'variant shape' => ['secret', '', ['secret']];
        yield 'unknown key' => ['secret', '', [['referenceIndex' => 0, 'alternatives' => ['ok'], 'extra' => 'secret']]];
        yield 'unknown index' => ['secret', '', [['referenceIndex' => 1, 'alternatives' => ['ok']]]];
        yield 'negative index' => ['secret', '', [['referenceIndex' => -1, 'alternatives' => ['ok']]]];
        yield 'string index' => ['secret', '', [['referenceIndex' => '0', 'alternatives' => ['ok']]]];
        yield 'duplicate index' => ['secret', '', array_fill(0, 2, ['referenceIndex' => 0, 'alternatives' => ['ok']])];
        yield 'no alternatives' => ['secret', '', [['referenceIndex' => 0, 'alternatives' => []]]];
        yield 'non list alternatives' => ['secret', '', [['referenceIndex' => 0, 'alternatives' => ['x' => 'ok']]]];
        yield 'non string alternative' => ['secret', '', [['referenceIndex' => 0, 'alternatives' => [42]]]];
        yield 'empty alternative' => ['secret', '', [['referenceIndex' => 0, 'alternatives' => ['']]]];
        yield 'invalid variant UTF8' => ['secret', '', [['referenceIndex' => 0, 'alternatives' => ["\xFF"]]]];
        yield 'punctuation variant' => ['secret', '', [['referenceIndex' => 0, 'alternatives' => ['ok!']]]];
        yield 'four word variant' => ['secret', '', [['referenceIndex' => 0, 'alternatives' => ['un deux trois quatre']]]];
        yield 'long variant' => ['secret', '', [['referenceIndex' => 0, 'alternatives' => [str_repeat('a', 129)]]]];
        yield 'too many alternatives' => ['secret', '', [['referenceIndex' => 0, 'alternatives' => ['a', 'b', 'c', 'd', 'e']]]];
        yield 'duplicate alternative' => ['secret', '', [['referenceIndex' => 0, 'alternatives' => ['é', "e\u{0301}"]]]];
        yield 'canonical alternative' => ['secret', '', [['referenceIndex' => 0, 'alternatives' => ['secret']]]];
        yield 'too many passages' => [str_repeat('a ', 33), '', array_map(static fn (int $i): array => ['referenceIndex' => $i, 'alternatives' => ['b']], range(0, 32))];
        yield 'total variant length' => [str_repeat('a ', 32), '', array_map(static fn (int $i): array => ['referenceIndex' => $i, 'alternatives' => [str_repeat('b', 128), str_repeat('c', 128)]], range(0, 31))];
    }

    /** @param array<mixed> $variants */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputsAreExplicitAndDoNotRevealEditorialContent(string $reference, string $copy, array $variants): void
    {
        try {
            (new DictationCorrector())->correct($reference, $copy, $variants);
            self::fail('Expected validation failure.');
        } catch (\InvalidArgumentException $error) {
            self::assertNotSame('', $error->getMessage());
            self::assertStringNotContainsString('secret', $error->getMessage());
            self::assertStringNotContainsString('copie', $error->getMessage());
        }
    }

    public function testExactWordLimitsAreAccepted(): void
    {
        $result = (new DictationCorrector())->correct(implode(' ', array_fill(0, 500, 'a')), implode(' ', array_fill(0, 750, 'b')));
        self::assertSame(500, $result->referenceWordCount);
        self::assertSame(750, $result->copyWordCount);
        self::assertSame(750, $result->lexicalErrorCount);
        self::assertSame(0.0, $result->score());
    }

    public function testExactUtf16LimitAndLargePunctuationRunAreAccepted(): void
    {
        $text = str_repeat('漢', 20000);
        self::assertTrue((new DictationCorrector())->correct($text, $text)->noErrors());
        $result = (new DictationCorrector())->correct('a'.str_repeat('!', 19999), 'a'.str_repeat('?', 19999));
        self::assertSame(0, $result->lexicalErrorCount);
        self::assertSame(1, $result->punctuationErrorCount);
        self::assertSame([['domain' => 'punctuation', 'type' => 'substitution', 'start' => 1, 'end' => 20000]], $result->publicFeedback()['issues']);
        self::assertFalse($result->noErrors());
    }

    public function testLongCompoundAndCombiningSequenceDoNotExhaustRegexStackOrDisappear(): void
    {
        foreach ([str_repeat('a-', 9999).'a', 'a'.str_repeat("\u{0301}", 19999)] as $text) {
            $result = (new DictationCorrector())->correct($text, $text);
            self::assertSame(1, $result->referenceWordCount);
            self::assertSame(1, $result->copyWordCount);
            self::assertTrue($result->noErrors());
        }
    }

    public function testVariantBudgetBoundaryAndReferenceDenominator(): void
    {
        $variants = array_map(static fn (int $i): array => ['referenceIndex' => $i, 'alternatives' => [str_repeat('b', 128)]], range(0, 31));
        $result = (new DictationCorrector())->correct(implode(' ', array_fill(0, 32, 'a')), implode(' ', array_fill(0, 32, str_repeat('b', 128))), $variants);
        self::assertTrue($result->noErrors());
        self::assertSame(32, $result->referenceWordCount);
    }

    public function testFourWayFusionIsNotAcceptedAsASingleSegmentation(): void
    {
        $result = (new DictationCorrector())->correct('a b c d', 'abcd');
        self::assertSame(4, $result->lexicalErrorCount);
        self::assertSame(0.0, $result->score());
    }

    public function testNonSeparatorsAreNotSilentlyIgnored(): void
    {
        $tokenizer = new DictationTokenizer();
        self::assertSame(2, $tokenizer->countWords("l'école"));
        self::assertSame(2, $tokenizer->countWords("l' école"));
        $result = (new DictationCorrector())->correct('un deux', "un\u{200B}deux");
        self::assertSame(0, $result->lexicalErrorCount);
        self::assertSame(1, $result->punctuationErrorCount);
        self::assertSame([['domain' => 'punctuation', 'type' => 'insertion', 'start' => 2, 'end' => 3]], $result->publicFeedback()['issues']);
    }
}
