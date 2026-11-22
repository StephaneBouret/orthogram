<?php

namespace App\Tests\Services;

use App\Services\LocalQuizManifest;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class LocalQuizManifestTest extends TestCase
{
    /** @param list<int|string> $path */
    #[TestWith([['format'], 'orthogram.editorial.v2', 'Format'])]
    #[TestWith([['counts', 'copies'], 2, 'Décomptes'])]
    #[TestWith([['sources', 0, 'status'], 'on_hold', 'Source exclue'])]
    #[TestWith([['sources', 0, 'candidate', 'text'], 'Énoncé retouché', 'Empreinte de copie'])]
    #[TestWith([['sources', 0, 'candidate', 'answers', 1, 'correct'], true, 'Propositions'])]
    #[TestWith([['sources', 0, 'assignments'], [], 'Affectations'])]
    #[TestWith([['quizzes', 0, 'renewal_enabled'], true, 'Création fixe'])]
    #[TestWith([['quizzes', 0, 'initial_source_keys'], [], 'Ordre'])]
    #[TestWith([['quizzes', 0, 'theme_matrix', 0, 'per_attempt'], 2, 'quota'])]
    public function testRefusesInconsistentManifest(array $path, mixed $value, string $diagnostic): void
    {
        $root = dirname(__DIR__, 2);
        $data = json_decode(file_get_contents($root.'/tests/Fixtures/Quiz/essai.json'), true, flags: JSON_THROW_ON_ERROR);
        $field = &$data;
        foreach ($path as $key) {
            $field = &$field[$key];
        }
        $field = $value;
        $file = sys_get_temp_dir().'/orthogram-'.bin2hex(random_bytes(8)).'.json';
        try {
            file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR));
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage($diagnostic);
            new LocalQuizManifest($file, $root.'/public');
        } finally {
            unlink($file);
        }
    }

    public function testResolvesPublicDirectoryBeforeReading(): void
    {
        $dir = dirname(__DIR__).'/Fixtures/Quiz';
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('hors de la racine publique');
        new LocalQuizManifest($dir.'/../Quiz/essai.json', $dir);
    }
}
