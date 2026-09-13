<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
class QuizQuestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'questions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Choisissez le quiz de cette question.')]
    private ?Quiz $quiz = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(normalizer: 'trim', message: 'La consigne est obligatoire.')]
    #[Assert\Length(max: 255)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $text = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $explanation = '';

    #[ORM\Column]
    #[Assert\NotNull(message: 'Choisissez le mode de réponse.')]
    private ?bool $multiple = false;

    #[ORM\Column]
    #[Assert\NotNull(message: 'La position est obligatoire.')]
    #[Assert\PositiveOrZero(message: 'La position ne peut pas être négative.')]
    private ?int $position = 0;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $theme = null;

    /** @var Collection<int, QuizAnswer> */
    #[ORM\OneToMany(targetEntity: QuizAnswer::class, mappedBy: 'question', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    #[Assert\Count(min: 2, minMessage: 'Ajoutez au moins deux propositions.')]
    #[Assert\Valid]
    private Collection $answers;

    public function __construct()
    {
        $this->answers = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->title;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuiz(): ?Quiz
    {
        return $this->quiz;
    }

    public function setQuiz(?Quiz $quiz): static
    {
        if ($this->quiz === $quiz) {
            return $this;
        }
        $previous = $this->quiz;
        $this->quiz = $quiz;
        $previous?->removeQuestion($this);
        $quiz?->addQuestion($this);

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function getExplanation(): string
    {
        return $this->explanation;
    }

    public function setExplanation(string $explanation): static
    {
        $this->explanation = $explanation;

        return $this;
    }

    public function isMultiple(): ?bool
    {
        return $this->multiple;
    }

    public function setMultiple(?bool $multiple): static
    {
        $this->multiple = $multiple;

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

    public function getTheme(): ?string
    {
        return $this->theme;
    }

    public function setTheme(?string $theme): static
    {
        $this->theme = $theme;

        return $this;
    }

    /** @return Collection<int, QuizAnswer> */
    public function getAnswers(): Collection
    {
        return $this->answers;
    }

    public function addAnswer(QuizAnswer $answer): static
    {
        if (!$this->answers->contains($answer)) {
            $this->answers->add($answer);
        }
        if ($answer->getQuestion() !== $this) {
            $answer->setQuestion($this);
        }

        return $this;
    }

    public function removeAnswer(QuizAnswer $answer): static
    {
        if ($this->answers->removeElement($answer) && $answer->getQuestion() === $this) {
            $answer->setQuestion(null);
        }

        return $this;
    }

    #[Assert\Callback]
    public function validateConfiguration(ExecutionContextInterface $context): void
    {
        $correctCount = 0;
        foreach ($this->answers as $answer) {
            if ($answer->getQuestion() !== $this) {
                $context->buildViolation('Chaque proposition doit appartenir à cette question.')
                    ->atPath('answers')->addViolation();
            }
            if ($answer->isCorrect()) {
                ++$correctCount;
            }
        }

        if ((!$this->multiple && 1 !== $correctCount) || ($this->multiple && 0 === $correctCount)) {
            $context->buildViolation($this->multiple
                ? 'Indiquez au moins une bonne réponse.'
                : 'Indiquez exactement une bonne réponse en mode « Une seule réponse ».')
                ->atPath('answers')->addViolation();
        }

        if (null !== $this->quiz && !$this->quiz->getQuestions()->contains($this)) {
            $context->buildViolation('Le rattachement au quiz est incohérent.')
                ->atPath('quiz')->addViolation();
        }
    }
}
