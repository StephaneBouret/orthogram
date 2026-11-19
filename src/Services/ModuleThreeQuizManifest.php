<?php

namespace App\Services;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ModuleThreeQuizManifest extends EditorialQuizManifest
{
    public function __construct(#[Autowire('%kernel.project_dir%/resources/quiz/module3/module3_quiz_manifest.json')] string $path)
    {
        parent::__construct($path);
    }

    protected function validate(): void
    {
        // The Word-verified edition binds copies, assignments, theme matrices and excluded sources.
        self::require('fa86493da4fb4ede77c8246bf3f460b235183eb82f506ff2acdba08ca697e876' === $this->hash, 'Édition du manifeste module 3 non reconnue.');
        foreach ($this->data['copies'] as $copy) {
            self::require(self::hash($this->payload($copy)) === $copy['payload_sha256'], 'Empreinte de copie divergente : '.$copy['copy_key']);
        }
    }

    public function heldSourceKeys(): array
    {
        return array_column(array_filter($this->data['sources'], static fn ($source) => 'retained' !== $source['status']), 'source_key');
    }
}
