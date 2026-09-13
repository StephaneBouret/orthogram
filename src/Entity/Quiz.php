<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class Quiz
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(normalizer: 'trim', message: 'Le titre du quiz est obligatoire.')]
    #[Assert\Length(max: 255)]
    private string $title = '';

    /** @var Collection<int, QuizQuestion> */
    #[ORM\OneToMany(targetEntity: QuizQuestion::class, mappedBy: 'quiz', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    #[Assert\Valid]
    private Collection $questions;

    /** @var Collection<int, Courses> */
    #[ORM\OneToMany(targetEntity: Courses::class, mappedBy: 'quiz')]
    private Collection $courses;

    public function __construct()
    {
        $this->questions = new ArrayCollection();
        $this->courses = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->title;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    /** @return Collection<int, QuizQuestion> */
    public function getQuestions(): Collection
    {
        return $this->questions;
    }

    public function addQuestion(QuizQuestion $question): static
    {
        if (!$this->questions->contains($question)) {
            $this->questions->add($question);
        }
        if ($question->getQuiz() !== $this) {
            $question->setQuiz($this);
        }

        return $this;
    }

    public function removeQuestion(QuizQuestion $question): static
    {
        if ($this->questions->removeElement($question) && $question->getQuiz() === $this) {
            $question->setQuiz(null);
        }

        return $this;
    }

    /** @return Collection<int, Courses> */
    public function getCourses(): Collection
    {
        return $this->courses;
    }

    public function addCourse(Courses $course): static
    {
        if (!$this->courses->contains($course)) {
            $this->courses->add($course);
        }
        if ($course->getQuiz() !== $this) {
            $course->setQuiz($this);
        }

        return $this;
    }

    public function removeCourse(Courses $course): static
    {
        if ($this->courses->removeElement($course) && $course->getQuiz() === $this) {
            $course->setQuiz(null);
        }

        return $this;
    }
}
