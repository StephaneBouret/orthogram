<?php

namespace App\Services;

use App\Entity\User;
use App\Services\SendMailService;
use Scheb\TwoFactorBundle\Mailer\AuthCodeMailerInterface;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface;

final class SendEmail2faService implements AuthCodeMailerInterface
{
    public function __construct(protected SendMailService $email) {}

    public function sendAuthCode(TwoFactorInterface $user): void
    {
        if (!$user instanceof User) {
            throw new \LogicException('L\'utilisateur 2FA doit être une instance de App\Entity\User.');
        }

        $authCode = $user->getEmailAuthCode();

        if (null === $authCode) {
            throw new \LogicException('Le code 2FA email n\'a pas été généré.');
        }

        // Send Email
        $this->email->sendMail(
            'Code de vérification Orthogram',
            $user->getEmail(),
            'Code de vérification',
            'authentication',
            [
                'user' => $user,
                'authCode' => $authCode
            ],
            null
        );
    }
}
