<?php

namespace App\Entity;

use App\Enum\DeviceStatus;
use App\Repository\UserDeviceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserDeviceRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'UNIQ_USER_DEVICE_UUID', fields: ['user', 'deviceUuid'])]
class UserDevice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private ?string $deviceUuid = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'devices')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgentHash = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $deviceType = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $browser = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $platform = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $firstIp = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $lastIp = null;

    #[ORM\Column(length: 20, enumType: DeviceStatus::class)]
    private DeviceStatus $status = DeviceStatus::ACTIVE;

    #[ORM\Column]
    private ?\DateTimeImmutable $firstSeenAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();

        $this->firstSeenAt ??= $now;
        $this->lastUsedAt ??= $now;
        $this->createdAt ??= $now;
        $this->updatedAt ??= $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDeviceUuid(): ?string
    {
        return $this->deviceUuid;
    }

    public function setDeviceUuid(string $deviceUuid): static
    {
        $this->deviceUuid = trim($deviceUuid);

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

    public function getUserAgentHash(): ?string
    {
        return $this->userAgentHash;
    }

    public function setUserAgentHash(?string $userAgentHash): static
    {
        $this->userAgentHash = $userAgentHash;

        return $this;
    }

    public function getDeviceType(): ?string
    {
        return $this->deviceType;
    }

    public function setDeviceType(?string $deviceType): static
    {
        $this->deviceType = $deviceType;

        return $this;
    }

    public function getBrowser(): ?string
    {
        return $this->browser;
    }

    public function setBrowser(?string $browser): static
    {
        $this->browser = $browser;

        return $this;
    }

    public function getPlatform(): ?string
    {
        return $this->platform;
    }

    public function setPlatform(?string $platform): static
    {
        $this->platform = $platform;

        return $this;
    }

    public function getFirstIp(): ?string
    {
        return $this->firstIp;
    }

    public function setFirstIp(?string $firstIp): static
    {
        $this->firstIp = $firstIp;

        return $this;
    }

    public function getLastIp(): ?string
    {
        return $this->lastIp;
    }

    public function setLastIp(?string $lastIp): static
    {
        $this->lastIp = $lastIp;

        return $this;
    }

    public function getStatus(): DeviceStatus
    {
        return $this->status;
    }

    public function setStatus(DeviceStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getFirstSeenAt(): ?\DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function setFirstSeenAt(?\DateTimeImmutable $firstSeenAt): static
    {
        $this->firstSeenAt = $firstSeenAt;

        return $this;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): static
    {
        $this->lastUsedAt = $lastUsedAt;

        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeImmutable $revokedAt): static
    {
        $this->revokedAt = $revokedAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === DeviceStatus::ACTIVE && null === $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return $this->status === DeviceStatus::REVOKED || null !== $this->revokedAt;
    }

    public function markUsed(?string $ip = null): static
    {
        $now = new \DateTimeImmutable();

        $this->lastUsedAt = $now;
        $this->lastIp = $ip;

        return $this;
    }

    public function revoke(): static
    {
        $this->status = DeviceStatus::REVOKED;
        $this->revokedAt = new \DateTimeImmutable();

        return $this;
    }

    public function reactivate(): static
    {
        $this->status = DeviceStatus::ACTIVE;
        $this->revokedAt = null;
        $this->lastUsedAt = new \DateTimeImmutable();

        return $this;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s - %s',
            $this->deviceType ?? 'Appareil',
            $this->browser ?? $this->deviceUuid ?? 'Inconnu'
        );
    }
}
