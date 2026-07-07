<?php

namespace App\Entity;

use App\Enum\LessonStatus;
use App\Repository\LessonRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LessonRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_LESSON_USER_COURSE', fields: ['user', 'course'])]
class Lesson
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 20, enumType: LessonStatus::class)]
    private LessonStatus $status = LessonStatus::STUDY;

    #[ORM\Column]
    private ?\DateTimeImmutable $studiedAt = null;

    #[ORM\ManyToOne(inversedBy: 'lessons')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'lessons')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Courses $course = null;

    public function __construct()
    {
        $this->studiedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getStatus(): LessonStatus
    {
        return $this->status;
    }

    public function setStatus(LessonStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isDone(): bool
    {
        return LessonStatus::DONE === $this->status;
    }

    public function getStudiedAt(): ?\DateTimeImmutable
    {
        return $this->studiedAt;
    }

    public function setStudiedAt(\DateTimeImmutable $studiedAt): static
    {
        $this->studiedAt = $studiedAt;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getCourse(): ?Courses
    {
        return $this->course;
    }

    public function setCourse(?Courses $course): static
    {
        $this->course = $course;

        return $this;
    }
}
