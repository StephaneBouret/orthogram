<?php

declare(strict_types=1);

namespace App\Services;

use App\Dto\LearningReminderPayload;
use App\Enum\LearningReminderFrequency;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

final readonly class LearningReminderCalendarService
{
    public const DURATION_SECONDS = 900;
    public const PREPARATION_SECONDS = 300;
    public const TITLE = 'Mon rendez-vous d’apprentissage — Orthogram';
    public const SEPARATE_FIRST_NOTICE = 'Le premier rendez-vous sera ajouté séparément. Pour modifier ou supprimer l’ensemble dans votre agenda, intervenez sur ce rendez-vous et sur la série.';

    private UrlGeneratorInterface $urlGenerator;

    public function __construct(
        private LearningReminderNextRunCalculator $calculator,
        private LearningReminderIcalendarWriter $writer,
        UrlGeneratorInterface $urlGenerator,
        #[Autowire(env: 'DEFAULT_URI')] string $defaultUri = 'http://localhost',
    ) {
        $context = RequestContext::fromUri($defaultUri);
        // A cloned Router may still share its already initialized generator.
        // Build a separate generator from its routes; never change the shared context.
        $this->urlGenerator = $urlGenerator instanceof RouterInterface
            ? new UrlGenerator($urlGenerator->getRouteCollection(), $context)
            : clone $urlGenerator;
        $this->urlGenerator->setContext($context);
    }

    /** @return array{ics: string, googleUrl: ?string, expiresAt: string, notice: string, separateFirstNotice: ?string} */
    public function prepare(LearningReminderPayload $payload, \DateTimeImmutable $reference): array
    {
        $utc = new \DateTimeZone('UTC');
        $reference = $reference->setTimezone($utc);
        $time = \DateTimeImmutable::createFromFormat('!H:i', $payload->reminderTime, $utc);
        $date = null === $payload->scheduledDate ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $payload->scheduledDate, $utc);
        if (false === $time || false === $date) {
            throw new \InvalidArgumentException('Date ou heure invalide.');
        }
        $frequency = LearningReminderFrequency::from($payload->frequency);
        $first = $this->calculator->calculate($frequency, $time, $payload->weekdays, $date, $payload->timezone, $reference);
        $end = $first->modify('+'.self::DURATION_SECONDS.' seconds');
        $until = LearningReminderFrequency::ONCE === $frequency ? null : $this->fiveYearLimit($first);
        $nominal = $this->nominalStart($payload, $time, $first, $reference);
        $seriesNominal = null;
        if (null !== $until && $this->writer->requiresSeparateFirst($nominal, $payload->timezone, $first)) {
            $seriesNominal = $this->writer->nextSeriesNominal($payload, $nominal, $first, $until);
        }
        $url = $this->urlGenerator->generate('app_user_training', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $notice = null === $until
            ? 'Événement de 15 minutes.'
            : sprintf('Événements de 15 minutes sur cinq ans : débuts jusqu’au %s inclus (%s). Cette limite ne change pas la durée du rappel Orthogram.', $until->setTimezone(new \DateTimeZone($payload->timezone))->format('d/m/Y à H:i:s P'), $payload->timezone);
        $separateFirstNotice = null === $seriesNominal ? null : self::SEPARATE_FIRST_NOTICE;
        if (null !== $separateFirstNotice) {
            $notice .= ' '.$separateFirstNotice;
        }
        $description = 'Retrouvez votre entraînement Orthogram : '.$url."\n".$notice."\n".'Aucune synchronisation avec Orthogram. Des imports répétés peuvent créer des doublons.';
        $googleUrl = null;
        if (LearningReminderFrequency::ONCE === $frequency) {
            $googleUrl = 'https://calendar.google.com/calendar/render?'.http_build_query([
                'action' => 'TEMPLATE',
                'text' => self::TITLE,
                'dates' => $first->format('Ymd\THis\Z').'/'.$end->format('Ymd\THis\Z'),
                'stz' => $payload->timezone,
                'etz' => $payload->timezone,
                'details' => $description,
            ], '', '&', \PHP_QUERY_RFC3986);
        }
        // Every preparation expires no later than its first occurrence.
        $expires = $reference->modify('+'.self::PREPARATION_SECONDS.' seconds');
        if ($first < $expires) {
            $expires = $first;
        }

        return [
            'ics' => $this->writer->write($payload, $nominal, $first, $until, $reference, $url, $description, $seriesNominal),
            'googleUrl' => $googleUrl,
            'expiresAt' => $expires->format(\DateTimeInterface::ATOM),
            'notice' => $notice,
            'separateFirstNotice' => $separateFirstNotice,
        ];
    }

    public function fiveYearLimit(\DateTimeImmutable $first): \DateTimeImmutable
    {
        $first = $first->setTimezone(new \DateTimeZone('UTC'));
        $year = (int) $first->format('Y') + 5;
        $month = (int) $first->format('m');
        $lastDay = (int) $first->setDate($year, $month, 1)->format('t');

        return $first->setDate($year, $month, min((int) $first->format('d'), $lastDay));
    }

    private function nominalStart(LearningReminderPayload $payload, \DateTimeImmutable $time, \DateTimeImmutable $first, \DateTimeImmutable $reference): string
    {
        if (null !== $payload->scheduledDate) {
            return str_replace('-', '', $payload->scheduledDate).'T'.str_replace(':', '', $payload->reminderTime).'00';
        }
        // Keep the nominal civil date even when a timezone skips an entire day.
        // Resolve candidates with Orthogram's existing calculator, not PHP's normalization.
        $date = new \DateTimeImmutable($reference->setTimezone(new \DateTimeZone($payload->timezone))->format('Y-m-d'), new \DateTimeZone('UTC'));
        for ($day = 0; $day <= 8; ++$day, $date = $date->modify('+1 day')) {
            if ('weekly' === $payload->frequency && !\in_array((int) $date->format('N'), $payload->weekdays, true)) {
                continue;
            }
            try {
                $candidate = $this->calculator->calculate(LearningReminderFrequency::ONCE, $time, [], $date, $payload->timezone, $reference);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($candidate == $first) {
                return $date->format('Ymd').'T'.$time->format('His');
            }
        }
        throw new \LogicException('Date civile de la première occurrence introuvable.');
    }
}
