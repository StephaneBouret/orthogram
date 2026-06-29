<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LoginFailureLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LoginFailureLogRepository::class)]
#[ORM\Index(name: 'IDX_LOGIN_FAILURE_USER_OCCURRED_AT', fields: ['user', 'occurredAt'])]
class LoginFailureLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $usernameAttempted;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    public function __construct(string $usernameAttempted)
    {
        $this->usernameAttempted = mb_substr($usernameAttempted, 0, 255);
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsernameAttempted(): string
    {
        return $this->usernameAttempted;
    }

    public function setUsernameAttempted(string $usernameAttempted): static
    {
        $this->usernameAttempted = mb_substr($usernameAttempted, 0, 255);

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = $occurredAt;

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
}
