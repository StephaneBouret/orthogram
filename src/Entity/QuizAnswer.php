<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class QuizAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'answers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'La proposition doit appartenir à une question.')]
    private ?QuizQuestion $question = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(normalizer: 'trim', message: 'Le contenu de la proposition est obligatoire.')]
    private string $content = '';

    #[ORM\Column]
    private bool $correct = false;

    #[ORM\Column]
    #[Assert\NotNull(message: 'La position est obligatoire.')]
    #[Assert\PositiveOrZero(message: 'La position ne peut pas être négative.')]
    private ?int $position = 0;

    public function __toString(): string
    {
        return $this->content;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuestion(): ?QuizQuestion
    {
        return $this->question;
    }

    public function setQuestion(?QuizQuestion $question): static
    {
        if ($this->question === $question) {
            return $this;
        }
        $previous = $this->question;
        $this->question = $question;
        $previous?->removeAnswer($this);
        $question?->addAnswer($this);

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function isCorrect(): bool
    {
        return $this->correct;
    }

    public function setCorrect(bool $correct): static
    {
        $this->correct = $correct;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(?int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
