<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'quiz_reserve_import')]
#[ORM\UniqueConstraint(name: 'UNIQ_RESERVE_IMPORT_QUESTION', columns: ['question_id'])]
#[ORM\Index(name: 'IDX_RESERVE_IMPORT_QUIZ', columns: ['quiz_id'])]
final class QuizReserveImport
{
    public function __construct(
        #[ORM\Id, ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Quiz $quiz,
        #[ORM\Id, ORM\Column(length: 96)]
        private string $sourceKey,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?QuizQuestion $question,
        #[ORM\Column(length: 64)]
        private string $payloadHash,
        #[ORM\Column(length: 64)]
        private string $manifestHash,
    ) {
    }

    public function getQuestion(): ?QuizQuestion
    {
        return $this->question;
    }

    public function getQuiz(): Quiz
    {
        return $this->quiz;
    }

    public function getPayloadHash(): string
    {
        return $this->payloadHash;
    }

    public function getManifestHash(): string
    {
        return $this->manifestHash;
    }

    public function getSourceKey(): string
    {
        return $this->sourceKey;
    }
}
