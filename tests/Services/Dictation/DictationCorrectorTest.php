<?php

declare(strict_types=1);

namespace App\Tests\Services\Dictation;

use App\Services\Dictation\CorrectionContract;
use App\Services\Dictation\DictationCorrector;
use App\Services\Dictation\DictationTokenizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DictationCorrectorTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, int, int}> */
    public static function lexicalCases(): iterable
    {
        yield 'omission beginning' => ['petit chat', 'chat', 'omission', 0, 0];
        yield 'omission middle' => ['Le petit chat dort.', 'Le chat dort.', 'omission', 3, 3];
        yield 'omission end' => ['chat dort', 'chat', 'omission', 4, 4];
        yield 'addition beginning' => ['petit chat', 'très petit chat', 'insertion', 0, 4];
        yield 'addition middle' => ['petit chat', 'petit beau chat', 'insertion', 6, 10];
        yield 'addition end' => ['petit chat', 'petit chat noir', 'insertion', 11, 15];
        yield 'repeated reference' => ['chat chat dort', 'chat dort', 'omission', 0, 0];
        yield 'repeated copy' => ['chat dort', 'chat chat dort', 'insertion', 0, 4];
        yield 'accent' => ['école verte', 'ecole verte', 'substitution', 0, 5];
        yield 'case' => ['École verte', 'école verte', 'substitution', 0, 5];
        yield 'several mistakes in one word' => ['école verte', 'ECOLL verte', 'substitution', 0, 5];
        yield 'ligature not globally normalized' => ['cœur pur', 'coeur pur', 'substitution', 0, 5];
        yield 'apostrophe absent' => ['l’école verte', 'lecole verte', 'segmentation', 0, 6];
        yield 'hyphen absent' => ['porte-monnaie bleu', 'portemonnaie bleu', 'substitution', 0, 12];
        yield 'split apostrophe' => ['l’école verte', "l' école verte", 'segmentation', 0, 3];
        yield 'split compound' => ['porte-monnaie bleu', 'porte monnaie bleu', 'segmentation', 0, 13];
        yield 'fusion' => ['parce que oui', 'parceque oui', 'segmentation', 0, 8];
        yield 'three way split' => ['arc-en-ciel bleu', 'arc en ciel bleu', 'segmentation', 0, 11];
        yield 'three way fusion' => ['arc en ciel bleu', 'arcenciel bleu', 'segmentation', 0, 9];
        yield 'two to two segmentation' => ['ab-c d', 'ab c-d', 'segmentation', 0, 6];
        yield 'decomposed accent before error' => ['école verte', "e\u{0301}cole vert", 'substitution', 7, 11];
        yield 'non BMP before error' => ['😀 chat dort', '😀 chat dors', 'substitution', 8, 12];
        yield 'non BMP letter in word' => ['𐐀 chat', '𐐀 chats', 'substitution', 3, 8];
    }

    #[DataProvider('lexicalCases')]
    public function testLexicalAlignmentAndOriginalUtf16Positions(string $reference, string $copy, string $type, int $start, int $end): void
    {
        $result = (new DictationCorrector())->correct($reference, $copy);
        $feedback = $result->publicFeedback();
        self::assertSame(1, $feedback['lexicalErrorCount']);
        self::assertSame(0, $feedback['punctuationErrorCount']);
        self::assertSame([['domain' => 'lexical', 'type' => $type, 'start' => $start, 'end' => $end]], $feedback['issues']);
        self::assertSame(round(100 * (1 - 1 / $feedback['referenceWordCount']), 1), $feedback['score']);
        self::assertFalse($feedback['noErrors']);
        self::assertSame($reference, $result->referenceOriginal);
        self::assertSame($copy, $result->copyOriginal);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function equivalentCases(): iterable
    {
        yield 'perfect' => ['Le petit chat dort.', 'Le petit chat dort.', 4];
        yield 'apostrophes' => ['l’école', "l'école", 2];
        yield 'NFC' => ['école', "e\u{0301}cole", 1];
        yield 'spaces' => ['Le chat dort.', " Le\u{00A0}chat\u{202F}dort.\r\n", 3];
        yield 'unicode separators' => ['un deux trois', "un\u{2028}deux\u{2003}trois", 3];
        yield 'hyphen glyphs' => ['porte-monnaie', 'porte‑monnaie', 1];
        yield 'numerals' => ['12 petits chats', '12 petits chats', 3];
        yield 'identical external symbols' => ['« Chat 😀 ! »', '« Chat 😀 ! »', 1];
        yield 'Hangul NFC' => ['가 chat', "\u{1100}\u{1161} chat", 2];
        yield 'non lexical NFC' => ["chat !\u{0340}", "chat !\u{0300}", 1];
    }

    #[DataProvider('equivalentCases')]
    public function testEquivalenceAndSharedCounting(string $reference, string $copy, int $count): void
    {
        $feedback = (new DictationCorrector())->correct($reference, $copy)->publicFeedback();
        self::assertTrue($feedback['noErrors']);
        self::assertSame(100.0, $feedback['score']);
        self::assertSame([], $feedback['issues']);
        self::assertSame($count, $feedback['referenceWordCount']);
        self::assertSame($count, $feedback['copyWordCount']);
        self::assertSame($count, (new DictationTokenizer())->countWords($copy));
    }

    public function testEmptyCopyHasAllOmissionsAndNoSyntheticWords(): void
    {
        $feedback = (new DictationCorrector())->correct('un deux', '')->publicFeedback();
        self::assertSame(0.0, $feedback['score']);
        self::assertSame(0, $feedback['copyWordCount']);
        self::assertSame(2, $feedback['lexicalErrorCount']);
        self::assertSame(array_fill(0, 2, ['domain' => 'lexical', 'type' => 'omission', 'start' => 0, 'end' => 0]), $feedback['issues']);
        self::assertFalse($feedback['noErrors']);
    }

    /** @return iterable<string, array{string, float, int}> */
    public static function scoreCases(): iterable
    {
        yield 'perfect' => ['un deux trois quatre cinq six sept huit neuf dix', 100.0, 0];
        yield 'insertion' => ['un deux trois quatre cinq six sept huit neuf dix onze', 90.0, 1];
        yield 'omission' => ['un deux trois quatre cinq six sept huit neuf', 90.0, 1];
        yield 'substitution' => ['un deux trois quatre cinq six sept huit neuf DIX', 90.0, 1];
        yield 'empty' => ['', 0.0, 10];
        yield 'more errors than words' => [implode(' ', array_fill(0, 12, 'autre')), 0.0, 12];
    }

    #[DataProvider('scoreCases')]
    public function testTenWordScoring(string $copy, float $score, int $errors): void
    {
        $result = (new DictationCorrector())->correct('un deux trois quatre cinq six sept huit neuf dix', $copy);
        self::assertSame($score, $result->score());
        self::assertSame($errors, $result->lexicalErrorCount);
    }

    /** @return iterable<string, array{string, string, int, array<array{domain: string, type: string, start: int, end: int}>}> */
    public static function punctuationCases(): iterable
    {
        yield 'missing comma' => ['Le chat, dort.', 'Le chat dort.', 0, [
            ['domain' => 'punctuation', 'type' => 'omission', 'start' => 7, 'end' => 7],
        ]];
        yield 'added comma' => ['Le chat dort.', 'Le chat, dort.', 0, [
            ['domain' => 'punctuation', 'type' => 'insertion', 'start' => 7, 'end' => 8],
        ]];
        yield 'changed mark' => ['chat.', 'chat!', 0, [
            ['domain' => 'punctuation', 'type' => 'substitution', 'start' => 4, 'end' => 5],
        ]];
        yield 'end omission' => ['chat.', 'chat', 0, [
            ['domain' => 'punctuation', 'type' => 'omission', 'start' => 4, 'end' => 4],
        ]];
        yield 'initial omission' => ['« chat', 'chat', 0, [
            ['domain' => 'punctuation', 'type' => 'omission', 'start' => 0, 'end' => 0],
        ]];
        yield 'word and punctuation omitted' => ['Le petit, chat dort.', 'Le chat dort.', 1, [
            ['domain' => 'punctuation', 'type' => 'omission', 'start' => 2, 'end' => 2],
            ['domain' => 'lexical', 'type' => 'omission', 'start' => 3, 'end' => 3],
        ]];
        yield 'word omitted punctuation retained' => ['Le petit, chat dort.', 'Le, chat dort.', 1, [
            ['domain' => 'lexical', 'type' => 'omission', 'start' => 4, 'end' => 4],
        ]];
        yield 'word omitted punctuation added' => ['Le petit chat dort.', 'Le, chat dort.', 1, [
            ['domain' => 'punctuation', 'type' => 'insertion', 'start' => 2, 'end' => 3],
            ['domain' => 'lexical', 'type' => 'omission', 'start' => 4, 'end' => 4],
        ]];
        yield 'unknown symbol is evaluated' => ['chat', "chat\0", 0, [
            ['domain' => 'punctuation', 'type' => 'insertion', 'start' => 4, 'end' => 5],
        ]];
        yield 'punctuation group with common suffix' => ['chat?!', 'chat!', 0, [
            ['domain' => 'punctuation', 'type' => 'omission', 'start' => 4, 'end' => 4],
        ]];
        yield 'one changed zone not three characters' => ['chat?!;', 'chat.,:', 0, [
            ['domain' => 'punctuation', 'type' => 'substitution', 'start' => 4, 'end' => 7],
        ]];
    }

    /** @param list<array{domain: string, type: string, start: int, end: int}> $issues */
    #[DataProvider('punctuationCases')]
    public function testPunctuationHasItsOwnAnchoredZones(string $reference, string $copy, int $lexicalCount, array $issues): void
    {
        $feedback = (new DictationCorrector())->correct($reference, $copy)->publicFeedback();
        self::assertSame($issues, $feedback['issues']);
        self::assertSame($lexicalCount, $feedback['lexicalErrorCount']);
        self::assertSame(count($issues) - $lexicalCount, $feedback['punctuationErrorCount']);
        self::assertFalse($feedback['noErrors']);
        if (0 === $lexicalCount) {
            self::assertSame(100.0, $feedback['score']);
        }
    }

    public function testVariantsAreScopedToAnOccurrenceAndKeepCanonicalDenominator(): void
    {
        $corrector = new DictationCorrector();
        $variants = [['referenceIndex' => 0, 'alternatives' => ['coeur']]];
        self::assertTrue($corrector->correct('cœur cœur', 'coeur cœur', $variants)->noErrors());
        $wrongOccurrence = $corrector->correct('cœur cœur', 'cœur coeur', $variants);
        self::assertSame(1, $wrongOccurrence->lexicalErrorCount);
        self::assertSame(50.0, $wrongOccurrence->score());
        self::assertSame(2, $corrector->correct('cœur cœur', 'coeur coeur')->lexicalErrorCount);

        $expanded = $corrector->correct('bonjour ami', 'bon jour AMI', [['referenceIndex' => 0, 'alternatives' => ['bon jour']]]);
        self::assertSame(2, $expanded->referenceWordCount);
        self::assertSame(3, $expanded->copyWordCount);
        self::assertSame(50.0, $expanded->score());
        self::assertSame([['domain' => 'lexical', 'type' => 'substitution', 'start' => 9, 'end' => 12]], $expanded->publicFeedback()['issues']);
        $punctuation = $corrector->correct('bonjour ami', 'bon, jour ami', [['referenceIndex' => 0, 'alternatives' => ['bon jour']]]);
        self::assertSame(0, $punctuation->lexicalErrorCount);
        self::assertSame(1, $punctuation->punctuationErrorCount);
        self::assertFalse($punctuation->noErrors());
    }

    public function testPublicProjectionIsAnAllowlistAndContainsNoEditorialContent(): void
    {
        $result = (new DictationCorrector())->correct('CONFIDENTIEL', 'erreur', [['referenceIndex' => 0, 'alternatives' => ['SECRET']]]);
        $feedback = $result->publicFeedback();
        self::assertSame(['contractVersion', 'referenceWordCount', 'copyWordCount', 'lexicalErrorCount', 'punctuationErrorCount', 'score', 'noErrors', 'issues'], array_keys($feedback));
        self::assertSame(['domain', 'type', 'start', 'end'], array_keys($feedback['issues'][0]));
        $json = json_encode($feedback, JSON_THROW_ON_ERROR);
        foreach (['CONFIDENTIEL', 'SECRET', 'erreur', '<', 'referenceOriginal', 'alignment'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $json);
        }
        self::assertSame(CorrectionContract::VERSION, $feedback['contractVersion']);
    }

    public function testAmbiguousAlignmentsAreDeterministicAndCoverEveryWordExactlyOnce(): void
    {
        $corrector = new DictationCorrector();
        $reference = 'un un deux un deux';
        $copy = 'un deux un un deux';
        $expected = $corrector->correct($reference, $copy);
        for ($iteration = 0; $iteration < 10; ++$iteration) {
            $result = $corrector->correct($reference, $copy);
            self::assertSame($expected->alignment, $result->alignment);
            self::assertSame($expected->publicFeedback(), $result->publicFeedback());
        }
        $a = $b = 0;
        foreach ($expected->alignment as $step) {
            self::assertSame($a, $step['referenceStart']);
            self::assertSame($b, $step['copyStart']);
            $a += $step['referenceCount'];
            $b += $step['copyCount'];
        }
        self::assertSame(5, $a);
        self::assertSame(5, $b);
    }
}
