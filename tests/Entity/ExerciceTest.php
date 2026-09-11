<?php

namespace App\Tests\Entity;

use App\Entity\Exercice;
use PHPUnit\Framework\TestCase;

final class ExerciceTest extends TestCase
{
    public function testJsonAndFormRoundTripPreserveSentenceOptionsAndJoinedTokens(): void
    {
        $exercice = (new Exercice())->setDataAsJson(<<<'JSON'
            {"sentences":[{"id":"s4","noAnswer":true,"noAnswerLabel":"Aucun nom.","noAnswerExplanation":"Aucun nom dans cette phrase.","words":[{"text":"S’"},{"text":"ils","joinPrevious":true,"punctuationAfter":"!"}]}]}
            JSON);
        $sentence = $exercice->getSentences()[0];
        self::assertTrue($sentence['noAnswer']);
        self::assertArrayNotHasKey('noAnswerLabel', $sentence);
        self::assertSame('Aucun nom dans cette phrase.', $sentence['noAnswerExplanation']);
        self::assertTrue($sentence['words'][1]['joinPrevious']);
        self::assertSame(' !', $sentence['words'][1]['punctuationAfter']);
        self::assertSame('s4_w2', $sentence['words'][1]['id']);
        self::assertSame($exercice->getData(), (new Exercice())->setSentences($exercice->getSentences())->getData());
        self::assertSame($exercice->getData(), (new Exercice())->setDataAsJson($exercice->getDataAsJson())->getData());
    }
}
