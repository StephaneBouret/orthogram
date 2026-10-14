<?php

declare(strict_types=1);

namespace App\Tests\Services\Dictation;

use App\Services\Dictation\DictationCorrector;
use App\Services\Dictation\DictationTokenizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ElisionOmissionTest extends TestCase
{
    /** @return iterable<string, array{string, string, int, list<array{string, int, int}>}> */
    public static function cases(): iterable
    {
        yield 'intermediate omission' => ['L’école ouvre.', 'L’ ouvre.', 3, [['omission', 3, 3]]];
        yield 'retained following word' => ['L’école ouvre.', 'L’ école ouvre.', 3, [['segmentation', 0, 3]]];
        yield 'retained following word with accent error' => ['L’école ouvre.', 'L’ ecole ouvre.', 3, [['segmentation', 0, 3], ['substitution', 3, 8]]];
        yield 'final omission' => ['l’école', 'l’', 2, [['omission', 2, 2]]];
        yield 'fusion' => ['l’école', 'lecole', 2, [['segmentation', 0, 6]]];
        yield 'retained comma and period' => ['L’école, ouvre.', 'L’ , ouvre.', 3, [['omission', 5, 5]]];
        yield 'retained closing quote' => ['« L’école ouvre. »', '« L’ ouvre. »', 3, [['omission', 5, 5]]];
        yield 'non BMP before omission' => ['😀 L’école ouvre.', '😀 L’ ouvre.', 3, [['omission', 6, 6]]];
        yield 'decomposed accent before omission' => ['Été, L’école ouvre.', "E\u{0301}té, L’ ouvre.", 4, [['omission', 9, 9]]];
        yield 'nonbreaking residual space' => ['L’école ouvre.', "L’\u{00A0}ouvre.", 3, [['omission', 3, 3]]];
        yield 'two omitted words' => ['L’école maternelle ouvre.', 'L’ ouvre.', 4, [['omission', 3, 3], ['omission', 3, 3]]];
        yield 'independent prefix case error' => ['L’école ouvre.', 'l’ ouvre.', 3, [['substitution', 0, 2], ['omission', 3, 3]]];
        yield 'independent prefix apostrophe error' => ['L’école ouvre.', 'L‘ ouvre.', 3, [['substitution', 0, 2], ['omission', 3, 3]]];
        yield 'independent space before apostrophe' => ['L’école ouvre.', 'L ’ ouvre.', 3, [['segmentation', 0, 4], ['omission', 4, 4]]];
        yield 'independent retained word error' => ['L’école ouvre.', 'L’ ouvRE.', 3, [['omission', 3, 3], ['substitution', 3, 8]]];
        yield 'independent later elision separation' => ['L’école ouvre l’été.', 'L’ ouvre l’ été.', 5, [['omission', 3, 3], ['segmentation', 9, 12]]];
        yield 'repeated elision with only first omission' => ['L’école ouvre. L’école ouvre.', 'L’ ouvre. L’école ouvre.', 6, [['omission', 3, 3]]];
    }

    /** @param list<array{string, int, int}> $issues */
    #[DataProvider('cases')]
    public function testOnlyResidualBoundaryErrorIsWaived(string $reference, string $copy, int $count, array $issues): void
    {
        $engine = new DictationCorrector();
        $result = $engine->correct($reference, $copy);
        self::assertSame($count, $result->referenceWordCount);
        self::assertSame(count($issues), $result->lexicalErrorCount);
        self::assertSame(0, $result->punctuationErrorCount);
        self::assertSame(round(100 * (1 - count($issues) / $count), 1), $result->score());
        self::assertFalse($result->noErrors());
        self::assertSame(array_map(static fn (array $issue): array => [
            'domain' => 'lexical', 'type' => $issue[0], 'start' => $issue[1], 'end' => $issue[2],
        ], $issues), $result->publicFeedback()['issues']);
        self::assertSame($copy, $result->copyOriginal);
        self::assertSame($result->publicFeedback(), $engine->correct($reference, $copy)->publicFeedback());
    }

    public function testOmissionPreservesAlignmentAndOriginalSpansOfRetainedWords(): void
    {
        $copy = "😀 E\u{0301}té, L’ ouvre.";
        $result = (new DictationCorrector())->correct('😀 Été, L’école ouvre.', $copy);
        self::assertSame([
            ['type' => 'match', 'referenceStart' => 0, 'referenceCount' => 1, 'copyStart' => 0, 'copyCount' => 1],
            ['type' => 'match', 'referenceStart' => 1, 'referenceCount' => 1, 'copyStart' => 1, 'copyCount' => 1],
            ['type' => 'omission', 'referenceStart' => 2, 'referenceCount' => 1, 'copyStart' => 2, 'copyCount' => 0],
            ['type' => 'match', 'referenceStart' => 3, 'referenceCount' => 1, 'copyStart' => 2, 'copyCount' => 1],
        ], $result->alignment);
        $tokens = (new DictationTokenizer())->tokenize($copy);
        self::assertSame([[3, 7], [9, 11], [12, 17]], array_map(static fn (array $word): array => [$word['start'], $word['end']], $tokens->words));
        self::assertSame([['domain' => 'lexical', 'type' => 'omission', 'start' => 12, 'end' => 12]], $result->publicFeedback()['issues']);
    }
}
