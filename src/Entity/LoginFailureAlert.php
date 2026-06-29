<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LoginFailureAlertRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LoginFailureAlertRepository::class)]
#[ORM\Index(name: 'IDX_LOGIN_FAILURE_ALERT_USER_SENT_AT', fields: ['user', 'sentAt'])]
class LoginFailureAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private \DateTimeImmutable $sentAt;

    #[ORM\Column]
    private int $failureCount;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    public function __construct(User $user, int $failureCount)
    {
        $this->user = $user;
        $this->failureCount = $failureCount;
        $this->sentAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getFailureCount(): int
    {
        return $this->failureCount;
    }

    public function setFailureCount(int $failureCount): static
    {
        $this->failureCount = $failureCount;

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
}
