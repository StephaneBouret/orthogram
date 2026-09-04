<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\LearningReminder;
use App\Enum\LearningReminderEligibility;
use App\Enum\LearningReminderProcessingOutcome;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class LearningReminderProcessor
{
    public function __construct(
        private readonly LearningReminderEligibilityChecker $eligibilityChecker,
        private readonly LearningReminderNextRunCalculator $nextRunCalculator,
        private readonly LearningReminderViewService $viewService,
        private readonly SendMailService $sendMailService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(LearningReminder $reminder): LearningReminderProcessingOutcome
    {
        $processedAt = $this->clock
            ->now()
            ->setTimezone(new \DateTimeZone('UTC'));
        $eligibility = $this->eligibilityChecker->check($reminder->getUser(), $processedAt);

        if (LearningReminderEligibility::PERMANENTLY_INELIGIBLE === $eligibility) {
            $reminder->disable($processedAt);

            return LearningReminderProcessingOutcome::DISABLED;
        }

        if (LearningReminderEligibility::TEMPORARILY_INELIGIBLE === $eligibility) {
            if (!$reminder->getFrequency()->isRecurring()) {
                $reminder->disable($processedAt);

                return LearningReminderProcessingOutcome::DISABLED;
            }

            $nextRunAt = $this->calculateNextRun($reminder, $processedAt);
            $reminder->scheduleNextRunAt($nextRunAt, $processedAt);

            return LearningReminderProcessingOutcome::RESCHEDULED;
        }

        $user = $reminder->getUser();
        $email = $user->getEmail();

        if (null === $email || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new \UnexpectedValueException('Le destinataire du rappel ne possède pas une adresse e-mail utilisable.');
        }

        $summary = $this->viewService->present($reminder)['summary'];
        $trainingUrl = $this->urlGenerator->generate(
            'app_user_training',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $nextRunAt = $reminder->getFrequency()->isRecurring()
            ? $this->calculateNextRun($reminder, $processedAt)
            : null;

        $this->sendMailService->sendMail(
            'Orthogram',
            $email,
            'C’est le moment de poursuivre votre formation Orthogram',
            'learning_reminder',
            [
                'firstname' => $user->getFirstname(),
                'summary' => $summary,
                'trainingUrl' => $trainingUrl,
            ],
        );

        $reminder->markSent($processedAt, $nextRunAt, $processedAt);

        return LearningReminderProcessingOutcome::SENT;
    }

    private function calculateNextRun(
        LearningReminder $reminder,
        \DateTimeImmutable $processedAt,
    ): \DateTimeImmutable {
        return $this->nextRunCalculator->calculate(
            $reminder->getFrequency(),
            $reminder->getReminderTime(),
            $reminder->getWeekdays(),
            $reminder->getScheduledDate(),
            $reminder->getTimezone(),
            $processedAt,
        );
    }
}
