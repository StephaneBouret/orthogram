<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Stable editorial identity. Keep a tombstone even if its quiz is deleted. */
#[ORM\Entity]
#[ORM\Table(name: 'quiz_manifest_identity')]
#[ORM\UniqueConstraint(name: 'UNIQ_MANIFEST_QUIZ', columns: ['quiz_id'])]
final class QuizManifestIdentity
{
    public function __construct(
        #[ORM\Id, ORM\Column(length: 96)]
        private string $quizKey,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Quiz $quiz,
        #[ORM\Column(length: 64)]
        private string $manifestHash,
    ) {
    }

    public function getQuizKey(): string
    {
        return $this->quizKey;
    }

    public function getQuiz(): ?Quiz
    {
        return $this->quiz;
    }

    public function getManifestHash(): string
    {
        return $this->manifestHash;
    }
}
