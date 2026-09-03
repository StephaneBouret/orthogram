<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\UtcDateTimeImmutableType;
use App\Enum\LearningReminderFrequency;
use App\Repository\LearningReminderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LearningReminderRepository::class)]
#[ORM\UniqueConstraint(
    name: 'UNIQ_LEARNING_REMINDER_USER',
    columns: ['user_id'],
)]
#[ORM\Index(
    name: 'IDX_LEARNING_REMINDER_DUE',
    columns: ['enabled', 'next_run_at'],
)]
class LearningReminder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        name: 'user_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private User $user;

    #[ORM\Column(length: 10, enumType: LearningReminderFrequency::class)]
    private LearningReminderFrequency $frequency;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $reminderTime;

    /**
     * @var list<int>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $weekdays = [];

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledDate = null;

    #[ORM\Column(length: 64)]
    private string $timezone;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $nextRunAt = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $lastSentAt = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $updatedAt;

    private function __construct()
    {
    }

    /**
     * @param list<int> $weekdays
     */
    public static function create(
        User $user,
        LearningReminderFrequency $frequency,
        \DateTimeImmutable $reminderTime,
        array $weekdays,
        ?\DateTimeImmutable $scheduledDate,
        string $timezone,
        \DateTimeImmutable $nextRunAt,
        \DateTimeImmutable $createdAt,
    ): self {
        $reminder = new self();
        $reminder->user = $user;
        $reminder->createdAt = self::normalizeInstant($createdAt);
        $reminder->updatedAt = $reminder->createdAt;

        $reminder->reconfigure(
            $frequency,
            $reminderTime,
            $weekdays,
            $scheduledDate,
            $timezone,
            $nextRunAt,
            $createdAt,
        );

        return $reminder;
    }

    /**
     * @param list<int> $weekdays
     */
    public function reconfigure(
        LearningReminderFrequency $frequency,
        \DateTimeImmutable $reminderTime,
        array $weekdays,
        ?\DateTimeImmutable $scheduledDate,
        string $timezone,
        \DateTimeImmutable $nextRunAt,
        \DateTimeImmutable $updatedAt,
    ): void {
        $normalizedWeekdays = self::normalizeWeekdays($frequency, $weekdays);
        self::assertScheduledDateMatchesFrequency($frequency, $scheduledDate);
        self::assertIanaTimezone($timezone);

        $normalizedUpdatedAt = $this->normalizeUpdateInstant($updatedAt);
        $normalizedNextRunAt = self::normalizeInstant($nextRunAt);

        self::assertStrictlyFuture($normalizedNextRunAt, $normalizedUpdatedAt);

        $this->frequency = $frequency;
        $this->reminderTime = self::normalizeCivilTime($reminderTime);
        $this->weekdays = $normalizedWeekdays;
        $this->scheduledDate = null === $scheduledDate
            ? null
            : self::normalizeCivilDate($scheduledDate);
        $this->timezone = $timezone;
        $this->nextRunAt = $normalizedNextRunAt;
        $this->enabled = true;
        $this->updatedAt = $normalizedUpdatedAt;
    }

    public function scheduleNextRunAt(\DateTimeImmutable $nextRunAt, \DateTimeImmutable $updatedAt): void
    {
        if (!$this->enabled) {
            throw new \LogicException('Un rappel désactivé doit être reconfiguré avant d’être replanifié.');
        }

        $normalizedUpdatedAt = $this->normalizeUpdateInstant($updatedAt);
        $normalizedNextRunAt = self::normalizeInstant($nextRunAt);

        self::assertStrictlyFuture($normalizedNextRunAt, $normalizedUpdatedAt);

        $this->nextRunAt = $normalizedNextRunAt;
        $this->updatedAt = $normalizedUpdatedAt;
    }

    public function markSent(
        \DateTimeImmutable $sentAt,
        ?\DateTimeImmutable $nextRunAt,
        \DateTimeImmutable $updatedAt,
    ): void {
        if (!$this->enabled) {
            throw new \LogicException('Un rappel désactivé ne peut pas être marqué comme envoyé.');
        }

        $normalizedUpdatedAt = $this->normalizeUpdateInstant($updatedAt);
        $normalizedSentAt = self::normalizeInstant($sentAt);

        if ($normalizedSentAt < $this->createdAt) {
            throw new \InvalidArgumentException('La date d’envoi ne peut pas précéder la création du rappel.');
        }

        if (null !== $this->lastSentAt && $normalizedSentAt < $this->lastSentAt) {
            throw new \InvalidArgumentException('La date d’envoi ne peut pas précéder le dernier envoi.');
        }

        if ($normalizedSentAt > $normalizedUpdatedAt) {
            throw new \InvalidArgumentException('La date d’envoi ne peut pas être postérieure à la date de mise à jour.');
        }

        if ($this->frequency->isRecurring()) {
            if (null === $nextRunAt) {
                throw new \InvalidArgumentException('Un rappel récurrent envoyé doit recevoir une prochaine occurrence.');
            }

            $normalizedNextRunAt = self::normalizeInstant($nextRunAt);
            self::assertStrictlyFuture($normalizedNextRunAt, $normalizedUpdatedAt);

            $this->nextRunAt = $normalizedNextRunAt;
        } else {
            if (null !== $nextRunAt) {
                throw new \InvalidArgumentException('Un rappel unique envoyé ne doit pas recevoir de prochaine occurrence.');
            }

            $this->nextRunAt = null;
            $this->enabled = false;
        }

        $this->lastSentAt = $normalizedSentAt;
        $this->updatedAt = $normalizedUpdatedAt;
    }

    public function disable(\DateTimeImmutable $updatedAt): void
    {
        $normalizedUpdatedAt = $this->normalizeUpdateInstant($updatedAt);

        $this->enabled = false;
        $this->nextRunAt = null;
        $this->updatedAt = $normalizedUpdatedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getFrequency(): LearningReminderFrequency
    {
        return $this->frequency;
    }

    public function getReminderTime(): \DateTimeImmutable
    {
        return $this->reminderTime;
    }

    /**
     * @return list<int>
     */
    public function getWeekdays(): array
    {
        return $this->weekdays;
    }

    public function getScheduledDate(): ?\DateTimeImmutable
    {
        return $this->scheduledDate;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function getNextRunAt(): ?\DateTimeImmutable
    {
        return $this->nextRunAt;
    }

    public function getLastSentAt(): ?\DateTimeImmutable
    {
        return $this->lastSentAt;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @param list<mixed> $weekdays
     *
     * @return list<int>
     */
    private static function normalizeWeekdays(LearningReminderFrequency $frequency, array $weekdays): array
    {
        if (!$frequency->requiresWeekdays()) {
            if ([] !== $weekdays) {
                throw new \InvalidArgumentException('Seule une fréquence hebdomadaire peut définir des jours.');
            }

            return [];
        }

        if ([] === $weekdays) {
            throw new \InvalidArgumentException('Une fréquence hebdomadaire doit définir au moins un jour.');
        }

        $normalized = [];

        foreach ($weekdays as $weekday) {
            if (!\is_int($weekday) || $weekday < 1 || $weekday > 7) {
                throw new \InvalidArgumentException('Les jours doivent être des entiers ISO compris entre 1 et 7.');
            }

            $normalized[$weekday] = true;
        }

        $result = array_keys($normalized);
        sort($result, \SORT_NUMERIC);

        return $result;
    }

    private static function assertScheduledDateMatchesFrequency(
        LearningReminderFrequency $frequency,
        ?\DateTimeImmutable $scheduledDate,
    ): void {
        if ($frequency->requiresScheduledDate() && null === $scheduledDate) {
            throw new \InvalidArgumentException('Une fréquence unique doit définir une date.');
        }

        if (!$frequency->requiresScheduledDate() && null !== $scheduledDate) {
            throw new \InvalidArgumentException('Seule une fréquence unique peut définir une date.');
        }
    }

    private static function assertIanaTimezone(string $timezone): void
    {
        /** @var array<string, true>|null $identifiers */
        static $identifiers = null;

        $identifiers ??= array_fill_keys(
            \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),
            true,
        );

        if (!isset($identifiers[$timezone])) {
            throw new \InvalidArgumentException(sprintf('Le fuseau horaire "%s" n’est pas un identifiant IANA valide.', $timezone));
        }
    }

    private function normalizeUpdateInstant(\DateTimeImmutable $updatedAt): \DateTimeImmutable
    {
        $normalized = self::normalizeInstant($updatedAt);

        if ($normalized < $this->updatedAt) {
            throw new \InvalidArgumentException('La date de mise à jour ne peut pas reculer.');
        }

        return $normalized;
    }

    private static function assertStrictlyFuture(
        \DateTimeImmutable $instant,
        \DateTimeImmutable $reference,
    ): void {
        if ($instant <= $reference) {
            throw new \InvalidArgumentException('La prochaine occurrence doit être strictement future.');
        }
    }

    private static function normalizeCivilTime(\DateTimeImmutable $value): \DateTimeImmutable
    {
        $normalized = \DateTimeImmutable::createFromFormat(
            '!H:i:s',
            $value->format('H:i:s'),
            new \DateTimeZone('UTC'),
        );

        if (false === $normalized) {
            throw new \LogicException('Impossible de normaliser l’heure civile.');
        }

        return $normalized;
    }

    private static function normalizeCivilDate(\DateTimeImmutable $value): \DateTimeImmutable
    {
        $normalized = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value->format('Y-m-d'),
            new \DateTimeZone('UTC'),
        );

        if (false === $normalized) {
            throw new \LogicException('Impossible de normaliser la date civile.');
        }

        return $normalized;
    }

    private static function normalizeInstant(\DateTimeImmutable $value): \DateTimeImmutable
    {
        return $value->setTimezone(new \DateTimeZone('UTC'));
    }
}
