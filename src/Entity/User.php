<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use libphonenumber\PhoneNumber;
use Misd\PhoneNumberBundle\Validator\Constraints\PhoneNumber as AssertPhoneNumber;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;
use ZipCodeValidator\Constraints\ZipCode;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[ORM\UniqueConstraint(name: 'UNIQ_RESET_TOKEN', fields: ['resetToken'])]
#[UniqueEntity(fields: ['email'], message: 'Il existe un compte avec cet email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(
        message: 'Merci d\'indiquer votre adresse email.',
    )]
    #[Assert\Email(
        message: 'L\'adresse email {{ value }} est incorrecte.',
    )]
    private ?string $email = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(
        message: 'Merci d\'indiquer votre prénom.',
    )]
    #[Assert\Length(
        min: 3,
        max: 30,
        minMessage: 'Votre prénom doit comporter au moins {{ limit }} caractères.',
        maxMessage: 'Votre prénom ne peut excéder {{ limit }} caractères.',
    )]
    private ?string $firstname = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(
        message: 'Merci d\'indiquer votre nom.',
    )]
    #[Assert\Length(
        min: 2,
        minMessage: 'Votre nom doit comporter au moins {{ limit }} caractères.',
    )]
    private ?string $lastname = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(
        message: 'Merci d\'indiquer votre adresse.'
    )]
    private ?string $address = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(
        message: 'Merci d\'indiquer votre code postal.',
    )]
    #[ZipCode([
        'iso' => 'FR',
        'message' => 'Le code postal n\'est pas valide.'
    ])]
    private ?string $postalCode = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(
        message: 'Merci d\'indiquer votre ville.'
    )]
    private ?string $city = null;

    #[ORM\Column(type: 'phone_number')]
    #[Assert\NotBlank(
        message: 'Merci d\'indiquer votre numéro de téléphone.',
    )]
    #[AssertPhoneNumber(defaultRegion: 'FR')]
    private ?PhoneNumber $phone = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $resetToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resetTokenCreatedAt = null;

    public function __toString(): string
    {
        return "{$this->firstname} {$this->lastname}";
    }

    public function getId(): ?int
    {
        return $this->id;
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

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(string $firstname): static
    {
        $this->firstname = $firstname;

        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(string $lastname): static
    {
        $this->lastname = $lastname;

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

    public function getPhone(): ?PhoneNumber
    {
        return $this->phone;
    }

    public function setPhone(?PhoneNumber $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    /**
     * Retourne le nom complet de l'utilisateur
     *
     * @return string
     */
    public function getFullname(): string
    {
        return "{$this->firstname} {$this->lastname}";
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        if (null !== $this->password) {
            $data["\0" . self::class . "\0password"] = hash('crc32c', $this->password);
        }

        return $data;
    }

    public function getResetToken(): ?string
    {
        return $this->resetToken;
    }

    public function setResetToken(?string $resetToken): static
    {
        $this->resetToken = $resetToken;

        return $this;
    }

    public function getResetTokenCreatedAt(): ?\DateTimeImmutable
    {
        return $this->resetTokenCreatedAt;
    }

    public function setResetTokenCreatedAt(?\DateTimeImmutable $resetTokenCreatedAt): static
    {
        $this->resetTokenCreatedAt = $resetTokenCreatedAt;

        return $this;
    }
}
