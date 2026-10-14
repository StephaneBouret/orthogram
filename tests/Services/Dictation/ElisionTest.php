<?php

declare(strict_types=1);

namespace App\Tests\Services\Dictation;

use App\Services\Dictation\CorrectionContract;
use App\Services\Dictation\DictationCorrector;
use App\Services\Dictation\DictationTokenizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ElisionTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function counts(): iterable
    {
        yield 'article' => ['l’école', ["l'", 'école']];
        yield 'subject' => ['j’aime', ["j'", 'aime']];
        yield 'conjunction' => ['qu’il', ["qu'", 'il']];
        yield 'negation' => ['n’ont', ["n'", 'ont']];
        yield 'uppercase' => ["L'ÉCOLE", ["L'", 'ÉCOLE']];
        yield 'exception' => ['aujourd’hui', ["aujourd'hui"]];
        yield 'uppercase exception' => ["AUJOURD'HUI", ["AUJOURD'HUI"]];
        yield 'hyphenated compound' => ['porte-monnaie', ['porte-monnaie']];
        yield 'elision before compound' => ["l'amour-propre", ["l'", 'amour-propre']];
        yield 'elision before exception' => ["qu'aujourd'hui", ["qu'", "aujourd'hui"]];
        yield 'successive elisions' => ["qu'n'ont", ["qu'", "n'", 'ont']];
        yield 'c prefix' => ["c'est", ["c'", 'est']];
        yield 'd prefix' => ["d'abord", ["d'", 'abord']];
        yield 'm prefix' => ["m'aime", ["m'", 'aime']];
        yield 's prefix' => ["s'aime", ["s'", 'aime']];
        yield 't prefix' => ["t'aime", ["t'", 'aime']];
        yield 'jusqu prefix' => ["jusqu'ici", ["jusqu'", 'ici']];
        yield 'lorsqu prefix' => ["lorsqu'il", ["lorsqu'", 'il']];
        yield 'puisqu prefix' => ["puisqu'il", ["puisqu'", 'il']];
        yield 'unlisted prefix' => ["quelqu'un", ["quelqu'un"]];
        yield 'lexical unlisted form' => ["presqu'île", ["presqu'île"]];
        yield 'lexical unlisted compound' => ["prud'homme", ["prud'homme"]];
        yield 'proper name' => ['O’Neill', ["O'Neill"]];
        yield 'foreign expression' => ["rock'n'roll", ["rock'n'roll"]];
        yield 'successive then unlisted' => ["d'O'Neill", ["d'", "O'Neill"]];
    }

    /** @param list<string> $units */
    #[DataProvider('counts')]
    public function testExplicitElisionUnits(string $text, array $units): void
    {
        $tokenizer = new DictationTokenizer();
        $tokens = $tokenizer->tokenize($text);
        self::assertSame($units, array_column($tokens->words, 'value'));
        self::assertSame(count($units), $tokenizer->countWords($text));
        $result = (new DictationCorrector())->correct($text, $text);
        self::assertSame(count($units), $result->referenceWordCount);
        self::assertTrue($result->noErrors());
        self::assertSame($text, $tokens->original);
    }

    /** @return iterable<string, array{string, string, int, string, int, int}> */
    public static function errors(): iterable
    {
        yield 'fused without accent' => ['l’école', 'lecole', 2, 'segmentation', 0, 6];
        yield 'fused with accent' => ['l’école', 'lécole', 2, 'segmentation', 0, 6];
        yield 'fused with case and accent errors' => ['l’école', 'LECOLE', 2, 'segmentation', 0, 6];
        yield 'accent only' => ['l’école', "l'ecole", 2, 'substitution', 2, 7];
        yield 'space after apostrophe' => ['l’école', "l' école", 2, 'segmentation', 0, 3];
        yield 'space before apostrophe' => ['l’école', "l 'école", 2, 'segmentation', 0, 3];
        yield 'spaces both sides' => ['l’école', 'l ’ école', 2, 'segmentation', 0, 4];
        yield 'nonbreaking space' => ['l’école', "l'\u{00A0}école", 2, 'segmentation', 0, 3];
        yield 'newline after prefix' => ['l’école', "l'\nécole", 2, 'segmentation', 0, 3];
        yield 'double apostrophe' => ['l’école', "l''école", 2, 'substitution', 0, 3];
        yield 'double quote' => ['l’école', 'l"école', 2, 'substitution', 0, 2];
        yield 'left curly quote' => ['l’école', 'l‘école', 2, 'substitution', 0, 2];
        yield 'modifier apostrophe' => ['l’école', 'lʼécole', 2, 'substitution', 0, 2];
        yield 'backtick' => ['l’école', 'l`école', 2, 'substitution', 0, 2];
        yield 'acute sign' => ['l’école', 'l´école', 2, 'substitution', 0, 2];
        yield 'misplaced apostrophe' => ['l’école', "lé'cole", 2, 'segmentation', 0, 7];
        yield 'omitted prefix' => ['l’école', 'école', 2, 'omission', 0, 0];
        yield 'omitted last unit' => ['l’école', "l'", 2, 'omission', 2, 2];
        yield 'omitted last unit and trailing spaces' => ['l’école', "l'  ", 2, 'omission', 2, 2];
        yield 'omitted unit before retained period' => ['l’école.', "l'.", 2, 'omission', 2, 2];
        yield 'omitted next sentence after elision' => ['l’école. Demain', "l'école.", 3, 'omission', 8, 8];
        yield 'hyphen split' => ['porte-monnaie', 'porte monnaie', 1, 'segmentation', 0, 13];
        yield 'ordinary fusion' => ['parce que', 'parceque', 2, 'segmentation', 0, 8];
        yield 'incorrect apostrophe in lexical exception' => ['aujourd’hui', 'aujourd‘hui', 1, 'substitution', 0, 11];
        yield 'non BMP before error' => ['😀 l’école', "😀 l'ecole", 2, 'substitution', 5, 10];
        yield 'decomposed accent before error' => ['l’école verte', "l'e\u{0301}cole vert", 3, 'substitution', 9, 13];
    }

    #[DataProvider('errors')]
    public function testElisionErrorsRemainLexicalAndUseOriginalUtf16(string $reference, string $copy, int $count, string $type, int $start, int $end): void
    {
        $result = (new DictationCorrector())->correct($reference, $copy);
        self::assertSame($count, $result->referenceWordCount);
        self::assertSame(1, $result->lexicalErrorCount);
        self::assertSame(0, $result->punctuationErrorCount);
        self::assertSame(round(100 * (1 - 1 / $count), 1), $result->score());
        self::assertFalse($result->noErrors());
        self::assertSame([['domain' => 'lexical', 'type' => $type, 'start' => $start, 'end' => $end]], $result->publicFeedback()['issues']);
        self::assertSame($copy, $result->copyOriginal);
    }

    public function testApprovedAcceptanceExamples(): void
    {
        $reference = 'L’école ouvre ses portes aux enfants chaque lundi matin.';
        $engine = new DictationCorrector();
        $a = $engine->correct($reference, 'L’ecole ouvre ses porte aux enfants chaque lundi matin.');
        self::assertSame(10, $a->referenceWordCount);
        self::assertSame(10, $a->copyWordCount);
        self::assertSame(2, $a->lexicalErrorCount); // Accent AND agreement, no grammatical classifier.
        self::assertSame(0, $a->punctuationErrorCount);
        self::assertSame(80.0, $a->score());
        self::assertFalse($a->noErrors());
        self::assertSame([
            ['domain' => 'lexical', 'type' => 'substitution', 'start' => 2, 'end' => 7],
            ['domain' => 'lexical', 'type' => 'substitution', 'start' => 18, 'end' => 23],
        ], $a->publicFeedback()['issues']);

        $b = $engine->correct($reference, 'L’école ouvre ses portes aux enfants lundi matin');
        self::assertSame(10, $b->referenceWordCount);
        self::assertSame(9, $b->copyWordCount);
        self::assertSame(1, $b->lexicalErrorCount);
        self::assertSame(1, $b->punctuationErrorCount);
        self::assertSame(90.0, $b->score());
        self::assertFalse($b->noErrors());
        self::assertSame([
            ['domain' => 'lexical', 'type' => 'omission', 'start' => 37, 'end' => 37],
            ['domain' => 'punctuation', 'type' => 'omission', 'start' => 48, 'end' => 48],
        ], $b->publicFeedback()['issues']);
        self::assertSame('orthogram-dictation-v1', CorrectionContract::VERSION);
    }

    public function testTypographicEquivalenceDoesNotDiscardCaseAccentsOrSpacing(): void
    {
        $engine = new DictationCorrector();
        foreach ([['l’école', "l'e\u{0301}cole"], ['aujourd’hui', "aujourd'hui"], ['qu’aujourd’hui', "qu'aujourd'hui"]] as [$reference, $copy]) {
            self::assertTrue($engine->correct($reference, $copy)->noErrors());
        }
        $result = $engine->correct('l’école', "L'ECOLE");
        self::assertSame(2, $result->lexicalErrorCount);
        self::assertSame(0.0, $result->score());
        self::assertSame(2, $engine->correct('l’école', "l' ecole")->lexicalErrorCount);
        self::assertSame(2, $engine->correct('l’école l’école', "l' école l' école")->lexicalErrorCount);
        self::assertSame(1, $engine->correct('cœur', 'coeur')->lexicalErrorCount);
        self::assertSame(1, $engine->correct('école', 'ecole')->lexicalErrorCount);
        $punctuation = $engine->correct('l’école.', "l'école");
        self::assertSame(100.0, $punctuation->score());
        self::assertSame(1, $punctuation->punctuationErrorCount);
        self::assertFalse($punctuation->noErrors());
    }

    public function testVariantsUseNewUnitIndexesAndRespectElisionBoundaries(): void
    {
        $engine = new DictationCorrector();
        $variants = [['referenceIndex' => 1, 'alternatives' => ['ecole']]];
        self::assertTrue($engine->correct('l’école l’école', "l'ecole l'école", $variants)->noErrors());
        $other = $engine->correct('l’école l’école', "l'école l'ecole", $variants);
        self::assertSame(1, $other->lexicalErrorCount);
        self::assertSame(75.0, $other->score());
        self::assertSame([['domain' => 'lexical', 'type' => 'substitution', 'start' => 10, 'end' => 15]], $other->publicFeedback()['issues']);
        self::assertFalse($engine->correct('l’école', "l'ecole")->noErrors());

        $expansion = [['referenceIndex' => 0, 'alternatives' => ['l’école']]];
        $result = $engine->correct('bonjour ami', "l'école AMI", $expansion);
        self::assertSame(2, $result->referenceWordCount);
        self::assertSame(3, $result->copyWordCount);
        self::assertSame(50.0, $result->score());
        self::assertFalse($engine->correct('bonjour ami', "l' école ami", $expansion)->noErrors());
        $prefix = [['referenceIndex' => 0, 'alternatives' => ["d'"]]];
        self::assertTrue($engine->correct('l’école', "d'école", $prefix)->noErrors());
        self::assertFalse($engine->correct('l’école', "d' école", $prefix)->noErrors());
    }

    public function testLimitsApplyToElisionUnits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DictationCorrector())->correct(str_repeat("l'", 500).'école', '');
    }
}
