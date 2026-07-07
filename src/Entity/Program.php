<?php

namespace App\Entity;

use App\Repository\ProgramRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: ProgramRepository::class)]
#[Vich\Uploadable]
class Program
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le nom du programme est obligatoire !')]
    #[Assert\Length(min: 3, minMessage: 'Le nom du programme doit avoir au moins {{ limit }} caractères', max: 30, maxMessage: 'Le nom du programme ne peut excéder {{ limit }} caractères')]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $slug = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'La description est obligatoire !')]
    private ?string $description = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero(message: 'Le prix ne peut pas être négatif.')]
    private ?int $price = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $whyThisTrainingTitle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $whyThisTrainingContent = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $structuredProgramTitle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $structuredProgramContent = null;

    #[Assert\Image(
        maxSize: '2M',
        maxSizeMessage: 'L\'image est trop lourde ({{ size }} {{ suffix }}).
        Le maximum autorisé est {{ limit }} {{ suffix }}',
        minWidth: 400,
        minWidthMessage: 'La largeur de l\'image est trop petite ({{ width }}px).
        Le minimum est {{ min_width }}px.',
        minHeight: 400,
        minHeightMessage: 'La hauteur est trop faible ({{ height }}px).
        Le minimum est {{ min_height }}px.',
        mimeTypes: [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/webp',
        ],
        mimeTypesMessage: 'Le type MIME du fichier n\'est pas valide ({{ type }}). Les formats autorisés sont {{ types }}'
    )]
    #[Vich\UploadableField(mapping: 'programs_images', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(nullable: true)]
    private ?string $imageName = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, ProgramHighlight>
     */
    #[ORM\OneToMany(targetEntity: ProgramHighlight::class, mappedBy: 'program', cascade: ['persist'], orphanRemoval: true)]
    private Collection $programHighlights;

    /**
     * @var Collection<int, ProgramDetail>
     */
    #[ORM\OneToMany(targetEntity: ProgramDetail::class, mappedBy: 'program', cascade: ['persist'], orphanRemoval: true)]
    private Collection $programDetails;

    /**
     * @var Collection<int, Sections>
     */
    #[ORM\OneToMany(targetEntity: Sections::class, mappedBy: 'program', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $sections;

    public function __construct()
    {
        $this->programHighlights = new ArrayCollection();
        $this->programDetails = new ArrayCollection();
        $this->sections = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name ?? '';
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function setPrice(int $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getWhyThisTrainingTitle(): ?string
    {
        return $this->whyThisTrainingTitle;
    }

    public function setWhyThisTrainingTitle(?string $whyThisTrainingTitle): static
    {
        $this->whyThisTrainingTitle = $whyThisTrainingTitle;

        return $this;
    }

    public function getWhyThisTrainingContent(): ?string
    {
        return $this->whyThisTrainingContent;
    }

    public function setWhyThisTrainingContent(?string $whyThisTrainingContent): static
    {
        $this->whyThisTrainingContent = $whyThisTrainingContent;

        return $this;
    }

    public function getStructuredProgramTitle(): ?string
    {
        return $this->structuredProgramTitle;
    }

    public function setStructuredProgramTitle(?string $structuredProgramTitle): static
    {
        $this->structuredProgramTitle = $structuredProgramTitle;

        return $this;
    }

    public function getStructuredProgramContent(): ?string
    {
        return $this->structuredProgramContent;
    }

    public function setStructuredProgramContent(?string $structuredProgramContent): static
    {
        $this->structuredProgramContent = $structuredProgramContent;

        return $this;
    }

    /**
     * If manually uploading a file (i.e. not using Symfony Form) ensure an instance
     * of 'UploadedFile' is injected into this setter to trigger the update. If this
     * bundle's configuration parameter 'inject_on_load' is set to 'true' this setter
     * must be able to accept an instance of 'File' as the bundle will inject one here
     * during Doctrine hydration.
     *
     * @param File|\Symfony\Component\HttpFoundation\File\UploadedFile|null $imageFile
     */
    public function setImageFile(?File $imageFile = null): void
    {
        $this->imageFile = $imageFile;

        if (null !== $imageFile) {
            // It is required that at least one field changes if you are using doctrine
            // otherwise the event listeners won't be called and the file is lost
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function getImageFile(): ?File
    {
        return $this->imageFile;
    }

    public function setImageName(?string $imageName): static
    {
        $this->imageName = $imageName;

        return $this;
    }

    public function getImageName(): ?string
    {
        return $this->imageName;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, ProgramHighlight>
     */
    public function getProgramHighlights(): Collection
    {
        return $this->programHighlights;
    }

    public function addProgramHighlight(ProgramHighlight $programHighlight): static
    {
        if (!$this->programHighlights->contains($programHighlight)) {
            $this->programHighlights->add($programHighlight);
            $programHighlight->setProgram($this);
        }

        return $this;
    }

    public function removeProgramHighlight(ProgramHighlight $programHighlight): static
    {
        if ($this->programHighlights->removeElement($programHighlight)) {
            // set the owning side to null (unless already changed)
            if ($programHighlight->getProgram() === $this) {
                $programHighlight->setProgram(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ProgramDetail>
     */
    public function getProgramDetails(): Collection
    {
        return $this->programDetails;
    }

    public function addProgramDetail(ProgramDetail $programDetail): static
    {
        if (!$this->programDetails->contains($programDetail)) {
            $this->programDetails->add($programDetail);
            $programDetail->setProgram($this);
        }

        return $this;
    }

    public function removeProgramDetail(ProgramDetail $programDetail): static
    {
        if ($this->programDetails->removeElement($programDetail)) {
            // set the owning side to null (unless already changed)
            if ($programDetail->getProgram() === $this) {
                $programDetail->setProgram(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Sections>
     */
    public function getSections(): Collection
    {
        return $this->sections;
    }

    public function addSection(Sections $section): static
    {
        if (!$this->sections->contains($section)) {
            $this->sections->add($section);
            $section->setProgram($this);
        }

        return $this;
    }

    public function removeSection(Sections $section): static
    {
        if ($this->sections->removeElement($section)) {
            // set the owning side to null (unless already changed)
            if ($section->getProgram() === $this) {
                $section->setProgram(null);
            }
        }

        return $this;
    }
}
