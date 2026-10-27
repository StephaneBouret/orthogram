<?php

namespace App\Tests\Services;

use App\Services\ModuleTwoQuizManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleTwoQuizManifestTest extends TestCase
{
    public function testExactDeliveredBytesAndCanonicalPayloads(): void
    {
        $manifest = new ModuleTwoQuizManifest(dirname(__DIR__, 2).'/resources/quiz/module2/module2_quiz_manifest.json');
        self::assertSame('2ce76e2738a3f8221fd0c121861b59d1888c13d6259930a6d539959ef39a2571', $manifest->hash);
        self::assertCount(116, $manifest->sources());
        foreach ($manifest->data['copies'] as $copy) {
            self::assertSame($copy['payload_sha256'], ModuleTwoQuizManifest::hash($manifest->payload($copy)), $copy['copy_key']);
        }
        self::assertSame(hash('sha256', '{"a":"é/à","z":[{"a":false,"b":true}]}'), ModuleTwoQuizManifest::hash(['z' => [['b' => true, 'a' => false]], 'a' => 'é/à']));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        foreach (['held', 'category', 'answer', 'theme', 'position', 'role', 'hash', 'quiz_key', 'counts', 'binding', 'renewal', 'assignment', 'theme_quota'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidContract(string $mode): void
    {
        $data = json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/quiz/module2/module2_quiz_manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        switch ($mode) {
            case 'held': $data['sources'][1]['candidate'] = $data['sources'][0]['candidate'];
                break;
            case 'category': $data['sources'][0]['candidate']['contains_error'] = null;
                break;
            case 'answer': $data['sources'][0]['candidate']['answers'][0]['correct'] = true;
                break;
            case 'theme': $data['sources'][0]['candidate']['theme'] = 'Thème inconnu';
                break;
            case 'position': $data['copies'][0]['position'] = 200;
                break;
            case 'role': $data['copies'][0]['role'] = 'reserve';
                break;
            case 'hash': $data['copies'][0]['payload_sha256'] = str_repeat('0', 64);
                break;
            case 'quiz_key': $data['quizzes'][0]['quiz_key'] = 'module1_test1';
                break;
            case 'counts': $data['quizzes'][0]['expected_initial_count'] = 13;
                break;
            case 'binding': $data['quizzes'][0]['course_binding']['existing_course_id'] = 1;
                break;
            case 'renewal': $data['quizzes'][0]['renewal_enabled'] = true;
                break;
            case 'assignment': $data['sources'][0]['assignments'] = [];
                break;
            case 'theme_quota': $data['quizzes'][0]['themes'][0]['initial_count_and_attempt_quota'] = 1;
                break;
        }
        $file = tempnam(sys_get_temp_dir(), 'module2-manifest-');
        try {
            file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR));
            $this->expectException(\DomainException::class);
            new ModuleTwoQuizManifest($file);
        } finally {
            unlink($file);
        }
    }
}
