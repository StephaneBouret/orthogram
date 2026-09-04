<?php

namespace App\Entity;

use App\Enum\SubscriptionStatus;
use App\Repository\SubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Subscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(
        message: 'Merci d\'indiquer une adresse email.',
    )]
    #[Assert\Email(
        message: 'L\'adresse email {{ value }} n\'est pas une adresse valide.',
    )]
    private ?string $email = null;

    #[ORM\Column(length: 20, enumType: SubscriptionStatus::class)]
    private SubscriptionStatus $status = SubscriptionStatus::PENDING;

    #[ORM\Column]
    #[Assert\Positive(
        message: 'Le prix doit être supérieur à zéro.',
    )]
    private int $priceCents = 10000;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(
        message: 'Merci d\'indiquer un titre.',
    )]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isLifetime = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $paymentReference = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $termsAcceptedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $immediateAccessRequestedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $withdrawalRightWaivedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'subscriptions')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reminder30SentAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reminder15SentAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();

        $this->createdAt ??= $now;
        $this->updatedAt ??= $now;
        $this->title ??= 'Abonnement praticien annuel';
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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getStatus(): SubscriptionStatus
    {
        return $this->status;
    }

    public function getEffectiveStatus(): SubscriptionStatus
    {
        if (SubscriptionStatus::EXPIRED === $this->status) {
            return SubscriptionStatus::EXPIRED;
        }

        if (
            SubscriptionStatus::ACTIVE === $this->status
            && !$this->isLifetime
            && null !== $this->endsAt
            && new \DateTimeImmutable() > $this->endsAt
        ) {
            return SubscriptionStatus::EXPIRED;
        }

        return $this->status;
    }

    public function getEffectiveStatusLabel(): string
    {
        return $this->getEffectiveStatus()->label();
    }

    public function setStatus(SubscriptionStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getPriceCents(): int
    {
        return $this->priceCents;
    }

    public function setPriceCents(int $priceCents): static
    {
        $this->priceCents = $priceCents;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = trim($title);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = null !== $description ? trim($description) : null;
        $this->description = '' === $description ? null : $description;

        return $this;
    }

    public function isLifetime(): bool
    {
        return $this->isLifetime;
    }

    public function setIsLifetime(bool $isLifetime): static
    {
        $this->isLifetime = $isLifetime;

        return $this;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(?\DateTimeImmutable $endsAt): static
    {
        $this->endsAt = $endsAt;

        return $this;
    }

    public function getPaymentReference(): ?string
    {
        return $this->paymentReference;
    }

    public function setPaymentReference(?string $paymentReference): static
    {
        $paymentReference = null !== $paymentReference ? trim($paymentReference) : null;
        $this->paymentReference = '' === $paymentReference ? null : $paymentReference;

        return $this;
    }

    public function getTermsAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->termsAcceptedAt;
    }

    public function setTermsAcceptedAt(?\DateTimeImmutable $termsAcceptedAt): static
    {
        $this->termsAcceptedAt = $termsAcceptedAt;

        return $this;
    }

    public function getImmediateAccessRequestedAt(): ?\DateTimeImmutable
    {
        return $this->immediateAccessRequestedAt;
    }

    public function setImmediateAccessRequestedAt(?\DateTimeImmutable $immediateAccessRequestedAt): static
    {
        $this->immediateAccessRequestedAt = $immediateAccessRequestedAt;

        return $this;
    }

    public function getWithdrawalRightWaivedAt(): ?\DateTimeImmutable
    {
        return $this->withdrawalRightWaivedAt;
    }

    public function setWithdrawalRightWaivedAt(?\DateTimeImmutable $withdrawalRightWaivedAt): static
    {
        $this->withdrawalRightWaivedAt = $withdrawalRightWaivedAt;

        return $this;
    }

    public function markCheckoutConsents(): static
    {
        $now = new \DateTimeImmutable();

        $this->termsAcceptedAt = $now;
        $this->immediateAccessRequestedAt = $now;
        $this->withdrawalRightWaivedAt = $now;

        return $this;
    }

    public function resetCheckoutConsents(): static
    {
        $this->termsAcceptedAt = null;
        $this->immediateAccessRequestedAt = null;
        $this->withdrawalRightWaivedAt = null;

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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getReminder30SentAt(): ?\DateTimeImmutable
    {
        return $this->reminder30SentAt;
    }

    public function setReminder30SentAt(?\DateTimeImmutable $reminder30SentAt): static
    {
        $this->reminder30SentAt = $reminder30SentAt;

        return $this;
    }

    public function getReminder15SentAt(): ?\DateTimeImmutable
    {
        return $this->reminder15SentAt;
    }

    public function setReminder15SentAt(?\DateTimeImmutable $reminder15SentAt): static
    {
        $this->reminder15SentAt = $reminder15SentAt;

        return $this;
    }

    public function markReminder30Sent(): static
    {
        $this->reminder30SentAt = new \DateTimeImmutable();

        return $this;
    }

    public function markReminder15Sent(): static
    {
        $this->reminder15SentAt = new \DateTimeImmutable();

        return $this;
    }

    public function isPending(): bool
    {
        return SubscriptionStatus::PENDING === $this->status;
    }

    public function isActive(): bool
    {
        return $this->isActiveAt(new \DateTimeImmutable());
    }

    public function isActiveAt(\DateTimeImmutable $at): bool
    {
        if (SubscriptionStatus::ACTIVE !== $this->status) {
            return false;
        }

        if ($this->isLifetime) {
            return true;
        }

        if (null !== $this->startsAt && $at < $this->startsAt) {
            return false;
        }

        if (null !== $this->endsAt && $at > $this->endsAt) {
            return false;
        }

        return true;
    }

    public function isExpired(): bool
    {
        if (SubscriptionStatus::EXPIRED === $this->status) {
            return true;
        }

        if ($this->isLifetime) {
            return false;
        }

        return null !== $this->endsAt && new \DateTimeImmutable() > $this->endsAt;
    }

    public function isCancelled(): bool
    {
        return SubscriptionStatus::CANCELLED === $this->status;
    }

    public function isSuspended(): bool
    {
        return SubscriptionStatus::SUSPENDED === $this->status;
    }

    public function activateForOneYear(
        ?\DateTimeImmutable $startDate = null,
        ?string $paymentReference = null,
    ): static {
        $startDate ??= new \DateTimeImmutable();

        $this->status = SubscriptionStatus::ACTIVE;
        $this->isLifetime = false;
        $this->startsAt = $startDate;
        $this->endsAt = $startDate->modify('+1 year');
        $this->paymentReference = $paymentReference;

        return $this;
    }

    public function activateLifetime(?string $paymentReference = null): static
    {
        $this->status = SubscriptionStatus::ACTIVE;
        $this->isLifetime = true;
        $this->startsAt = new \DateTimeImmutable();
        $this->endsAt = null;
        $this->paymentReference = $paymentReference;

        return $this;
    }

    public function markExpired(): static
    {
        $this->status = SubscriptionStatus::EXPIRED;

        return $this;
    }

    public function cancel(): static
    {
        $this->status = SubscriptionStatus::CANCELLED;

        return $this;
    }

    public function suspend(): static
    {
        $this->status = SubscriptionStatus::SUSPENDED;

        return $this;
    }

    public function reactivate(): static
    {
        $this->status = SubscriptionStatus::ACTIVE;

        return $this;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s - %s',
            $this->email ?? 'Souscription',
            $this->status->label()
        );
    }
}
