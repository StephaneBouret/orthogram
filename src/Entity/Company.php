<?php

namespace App\Entity;

use App\Enum\CompanyType;
use App\Repository\CompanyRepository;
use Doctrine\ORM\Mapping as ORM;
use libphonenumber\PhoneNumber;
use Misd\PhoneNumberBundle\Validator\Constraints\PhoneNumber as AssertPhoneNumber;
use Symfony\Component\Validator\Constraints as Assert;
use ZipCodeValidator\Constraints\ZipCode;

#[ORM\Entity(repositoryClass: CompanyRepository::class)]
class Company
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(
        message: 'Merci d\'indiquer le nom de l\'entreprise'
    )]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(
        message: 'Merci d\'indiquer votre adresse'
    )]
    private ?string $address = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(
        message: 'Merci d\'indiquer votre code postal'
    )]
    #[ZipCode([
        'iso' => 'FR',
        'message' => 'Le code postal n\'est pas valide'
    ])]
    private ?string $postalCode = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(
        message: 'Merci d\'indiquer votre ville'
    )]
    private ?string $city = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(
        message: 'Merci d\'indiquer votre adresse e-mail'
    )]
    #[Assert\Email(
        message: 'L\'adresse e-mail {{ value }} est incorrecte',
    )]
    private ?string $email = null;

    #[ORM\Column(type: 'phone_number')]
    #[Assert\NotBlank(
        message: 'Merci d\'indiquer votre numéro de téléphone'
    )]
    #[AssertPhoneNumber(defaultRegion: 'FR')]
    private ?PhoneNumber $phone = null;

    #[ORM\Column(enumType: CompanyType::class)]
    #[Assert\NotNull(message: 'Merci d\'indiquer le type d\'entreprise.')]
    private ?CompanyType $type = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(
        message: 'Merci d\'indiquer votre SIREN ou SIRET'
    )]
    #[Assert\Regex(
        pattern: '/^\d+$/',
        message: 'Votre SIREN ou SIRET doit contenir uniquement des chiffres',
    )]
    #[Assert\Length(
        min: 9,
        max: 14,
        minMessage: 'Votre SIREN ou SIRET doit comporter au moins {{ limit }} chiffres',
        maxMessage: 'Votre SIREN ou SIRET doit comporter au maximum {{ limit }} chiffres',
    )]
    private ?string $siren = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(
        message: 'Merci d\'indiquer l\'url'
    )]
    #[Assert\Url(
        message: 'L\'url {{ value }} n\'est pas valide'
    )]
    private ?string $url = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(
        message: 'Merci d\'indiquer le prénom et le nom du dirigeant'
    )]
    private ?string $manager = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $websiteCreator = null;

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

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(string $postalCode): static
    {
        $this->postalCode = $postalCode;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?PhoneNumber
    {
        return $this->phone;
    }

    public function setPhone(?PhoneNumber $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getType(): ?CompanyType
    {
        return $this->type;
    }

    public function setType(CompanyType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getSiren(): ?string
    {
        return $this->siren;
    }

    public function setSiren(string $siren): static
    {
        $this->siren = $siren;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getManager(): ?string
    {
        return $this->manager;
    }

    public function setManager(string $manager): static
    {
        $this->manager = $manager;

        return $this;
    }

    public function getWebsiteCreator(): ?string
    {
        return $this->websiteCreator;
    }

    public function setWebsiteCreator(?string $websiteCreator): static
    {
        $this->websiteCreator = $websiteCreator;

        return $this;
    }
}
