<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\UtcDateTimeImmutableType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Dictation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Sections $section;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Sections $section)
    {
        $this->section = $section;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSection(): Sections
    {
        return $this->section;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
