<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\LoginFailureAlert;
use App\Entity\User;
use App\Repository\LoginFailureAlertRepository;
use App\Repository\LoginFailureLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

class LoginFailureAlertService
{
    private const FAILURE_THRESHOLD = 30;
    private const ALERT_WINDOW = '-24 hours';
    private const SUBJECT = 'Tentatives de connexion détectées sur votre compte';

    public function __construct(
        private readonly LoginFailureLogRepository $loginFailureLogRepository,
        private readonly LoginFailureAlertRepository $loginFailureAlertRepository,
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $defaultFrom,
    ) {
    }

    public function notifyIfNeeded(User $user, ?string $ipAddress = null): void
    {
        $emailAddress = $user->getEmail();

        if (null === $emailAddress || '' === $emailAddress) {
            return;
        }

        $since = new \DateTimeImmutable(self::ALERT_WINDOW);
        $failureCount = $this->loginFailureLogRepository->countRecentFailuresForUser($user, $since);

        if ($failureCount < self::FAILURE_THRESHOLD) {
            return;
        }

        if ($this->loginFailureAlertRepository->hasRecentAlertForUser($user, $since)) {
            return;
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->defaultFrom, 'Orthogram'))
            ->to($emailAddress)
            ->subject(self::SUBJECT)
            ->htmlTemplate('emails/login_failure_alert.html.twig')
            ->context([
                'user' => $user,
            ])
        ;

        $this->mailer->send($email);

        $alert = (new LoginFailureAlert($user, $failureCount))
            ->setIpAddress($ipAddress)
        ;

        $this->entityManager->persist($alert);
        $this->entityManager->flush();
    }
}
