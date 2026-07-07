<?php

namespace App\Entity;

use App\Enum\CommentReportStatus;
use App\Repository\CommentReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommentReportRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_COMMENT_REPORT_REPORTER', fields: ['comment', 'reporter'])]
class CommentReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Comment $comment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $reporter = null;

    #[ORM\Column(length: 20, enumType: CommentReportStatus::class)]
    private CommentReportStatus $status = CommentReportStatus::PENDING;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $commentContent = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $resolvedBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getComment(): ?Comment
    {
        return $this->comment;
    }

    public function setComment(?Comment $comment): static
    {
        $this->comment = $comment;

        if (null !== $comment && null === $this->commentContent) {
            $this->commentContent = (string) $comment->getContent();
        }

        return $this;
    }

    public function getReporter(): ?User
    {
        return $this->reporter;
    }

    public function setReporter(?User $reporter): static
    {
        $this->reporter = $reporter;

        return $this;
    }

    public function getStatus(): CommentReportStatus
    {
        return $this->status;
    }

    public function setStatus(CommentReportStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStatusLabel(): string
    {
        return $this->status->label();
    }

    public function getStatusBadgeClass(): string
    {
        return $this->status->badgeClass();
    }

    public function isPending(): bool
    {
        return CommentReportStatus::PENDING === $this->status;
    }

    public function getCommentContent(): ?string
    {
        return $this->commentContent;
    }

    public function setCommentContent(string $commentContent): static
    {
        $this->commentContent = $commentContent;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): static
    {
        $this->resolvedAt = $resolvedAt;

        return $this;
    }

    public function getResolvedBy(): ?User
    {
        return $this->resolvedBy;
    }

    public function setResolvedBy(?User $resolvedBy): static
    {
        $this->resolvedBy = $resolvedBy;

        return $this;
    }
}
