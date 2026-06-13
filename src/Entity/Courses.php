<?php

namespace App\Entity;

use App\Enum\CourseContentType;
use App\Repository\CoursesRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: CoursesRepository::class)]
#[Vich\Uploadable]
class Courses
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: "Le nom du cours est obligatoire !")]
    #[Assert\Length(min: 3, max: 255, minMessage: "Le nom du cours doit avoir au moins {{ limit }} caractères")]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $slug = null;

    #[ORM\Column(length: 20, enumType: CourseContentType::class)]
    private ?CourseContentType $contentType = CourseContentType::Twig;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $shortDescription = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $correctionText = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero(message: "La position ne peut pas être négative.")]
    private ?int $position = 0;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: "La durée estimée ne peut pas être négative.")]
    private ?int $durationMinutes = null;

    #[Vich\UploadableField(mapping: 'courses_files', fileNameProperty: 'partialFileName')]
    private ?File $partialFile = null;

    #[ORM\Column(nullable: true)]
    private ?string $partialFileName = null;

    #[Vich\UploadableField(mapping: 'audios_files', fileNameProperty: 'audioFileName')]
    private ?File $audioFile = null;

    #[ORM\Column(nullable: true)]
    private ?string $audioFileName = null;

    #[Assert\File(
        maxSize: '200M',
        extensions: ['mp4'],
        extensionsMessage: "L'extension du fichier n'est pas valide ({{ extension }}). Les extensions autorisées sont {{ extensions }}.",
        mimeTypes: ['video/mp4'],
        mimeTypesMessage: "Le type MIME du fichier n'est pas valide ({{ type }}). Les formats autorisés sont {{ types }}"
    )]
    #[Vich\UploadableField(mapping: 'courses_videos', fileNameProperty: 'videoName')]
    private ?File $videoFile = null;

    #[ORM\Column(nullable: true)]
    private ?string $videoName = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'courses')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Sections $section = null;

    /**
     * @var Collection<int, Lesson>
     */
    #[ORM\OneToMany(targetEntity: Lesson::class, mappedBy: 'course', orphanRemoval: true)]
    private Collection $lessons;

    public function __construct()
    {
        $this->lessons = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name ?? 'Cours sans nom';
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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getContentType(): ?CourseContentType
    {
        return $this->contentType;
    }

    public function setContentType(CourseContentType $contentType): static
    {
        $this->contentType = $contentType;

        return $this;
    }

    public function getShortDescription(): ?string
    {
        return $this->shortDescription;
    }

    public function setShortDescription(?string $shortDescription): static
    {
        $this->shortDescription = $shortDescription;

        return $this;
    }

    public function getCorrectionText(): ?string
    {
        return $this->correctionText;
    }

    public function setCorrectionText(?string $correctionText): static
    {
        $this->correctionText = $correctionText;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getDurationMinutes(): ?int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(?int $durationMinutes): static
    {
        $this->durationMinutes = $durationMinutes;

        return $this;
    }

    /**
     * @param File|\Symfony\Component\HttpFoundation\File\UploadedFile|null $partialFile
     */
    public function setPartialFile(?File $partialFile = null): void
    {
        $this->partialFile = $partialFile;

        if (null !== $partialFile) {
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function getPartialFile(): ?File
    {
        return $this->partialFile;
    }

    public function getPartialFileName(): ?string
    {
        return $this->partialFileName;
    }

    public function setPartialFileName(?string $partialFileName): static
    {
        $this->partialFileName = $partialFileName;

        return $this;
    }

    /**
     * @param File|\Symfony\Component\HttpFoundation\File\UploadedFile|null $audioFile
     */
    public function setAudioFile(?File $audioFile = null): void
    {
        $this->audioFile = $audioFile;

        if (null !== $audioFile) {
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function getAudioFile(): ?File
    {
        return $this->audioFile;
    }

    public function getAudioFileName(): ?string
    {
        return $this->audioFileName;
    }

    public function setAudioFileName(?string $audioFileName): static
    {
        $this->audioFileName = $audioFileName;

        return $this;
    }

    /**
     * @param File|\Symfony\Component\HttpFoundation\File\UploadedFile|null $videoFile
     */
    public function setVideoFile(?File $videoFile = null): void
    {
        $this->videoFile = $videoFile;

        if (null !== $videoFile) {
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function getVideoFile(): ?File
    {
        return $this->videoFile;
    }

    public function getVideoName(): ?string
    {
        return $this->videoName;
    }

    public function setVideoName(?string $videoName): static
    {
        $this->videoName = $videoName;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getSection(): ?Sections
    {
        return $this->section;
    }

    public function setSection(?Sections $section): static
    {
        $this->section = $section;

        return $this;
    }

    /**
     * @return Collection<int, Lesson>
     */
    public function getLessons(): Collection
    {
        return $this->lessons;
    }

    public function addLesson(Lesson $lesson): static
    {
        if (!$this->lessons->contains($lesson)) {
            $this->lessons->add($lesson);
            $lesson->setCourse($this);
        }

        return $this;
    }

    public function removeLesson(Lesson $lesson): static
    {
        if ($this->lessons->removeElement($lesson)) {
            if ($lesson->getCourse() === $this) {
                $lesson->setCourse(null);
            }
        }

        return $this;
    }
}
