<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final readonly class LearningReminderPayload
{
    /**
     * @param array<array-key, mixed> $weekdays
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Sélectionnez une fréquence.')]
        #[Assert\Choice(
            choices: ['daily', 'weekly', 'once'],
            message: 'La fréquence sélectionnée est invalide.',
        )]
        public string $frequency,
        #[Assert\NotBlank(message: 'Renseignez une heure.')]
        #[Assert\Regex(
            pattern: '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
            message: 'L’heure doit respecter le format HH:mm.',
        )]
        public string $reminderTime,
        #[Assert\Count(
            max: 7,
            maxMessage: 'Vous ne pouvez pas sélectionner plus de sept jours.',
        )]
        #[Assert\All([
            new Assert\Type(type: 'integer', message: 'Chaque jour doit être un entier ISO.'),
            new Assert\Range(
                min: 1,
                max: 7,
                notInRangeMessage: 'Chaque jour doit être compris entre 1 et 7.',
            ),
        ])]
        public array $weekdays,
        #[Assert\Date(message: 'La date doit respecter le format YYYY-MM-DD.')]
        public ?string $scheduledDate,
        #[Assert\NotBlank(message: 'Le fuseau horaire est obligatoire.')]
        #[Assert\Length(max: 64, maxMessage: 'Le fuseau horaire est trop long.')]
        #[Assert\Timezone(
            zone: \DateTimeZone::ALL_WITH_BC,
            message: 'Le fuseau horaire doit être un identifiant IANA valide.',
        )]
        public string $timezone,
    ) {
    }

    #[Assert\Callback]
    public function validateConsistency(ExecutionContextInterface $context): void
    {
        if (!array_is_list($this->weekdays)) {
            $context
                ->buildViolation('Les jours doivent être transmis sous forme de liste.')
                ->atPath('weekdays')
                ->addViolation();
        }

        $integerWeekdays = array_filter(
            $this->weekdays,
            static fn (mixed $weekday): bool => \is_int($weekday),
        );

        if (
            \count($integerWeekdays) === \count($this->weekdays)
            && \count(array_unique($integerWeekdays)) !== \count($integerWeekdays)
        ) {
            $context
                ->buildViolation('Un même jour ne peut pas être sélectionné plusieurs fois.')
                ->atPath('weekdays')
                ->addViolation();
        }

        if (!\in_array($this->frequency, ['daily', 'weekly', 'once'], true)) {
            return;
        }

        if ('weekly' === $this->frequency && [] === $this->weekdays) {
            $context
                ->buildViolation('Sélectionnez au moins un jour.')
                ->atPath('weekdays')
                ->addViolation();
        }

        if ('weekly' !== $this->frequency && [] !== $this->weekdays) {
            $context
                ->buildViolation('Seule une fréquence hebdomadaire peut définir des jours.')
                ->atPath('weekdays')
                ->addViolation();
        }

        if (
            'once' === $this->frequency
            && (null === $this->scheduledDate || '' === trim($this->scheduledDate))
        ) {
            $context
                ->buildViolation('Renseignez une date.')
                ->atPath('scheduledDate')
                ->addViolation();
        }

        if ('once' !== $this->frequency && null !== $this->scheduledDate) {
            $context
                ->buildViolation('Seul un rappel unique peut définir une date.')
                ->atPath('scheduledDate')
                ->addViolation();
        }
    }
}
