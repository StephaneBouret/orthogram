<?php

namespace App\Services;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class ForgotIdentifierRequestService
{
    public function __construct(
        private readonly SendMailService $sendMailService,
        private readonly LoggerInterface $logger,
        private readonly string $supportEmail,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function notifySupport(array $data, Request $request): bool
    {
        if ('' !== trim((string) ($data['website'] ?? ''))) {
            $this->logger->notice('Demande d’identifiant oublié ignorée par honeypot.', [
                'ip' => $request->getClientIp(),
            ]);

            return true;
        }

        $context = [
            'requestedIdentifier' => mb_strtolower(trim((string) ($data['requestedIdentifier'] ?? ''))),
            'firstname' => trim((string) ($data['firstname'] ?? '')),
            'lastname' => trim((string) ($data['lastname'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'postalCode' => trim((string) ($data['postalCode'] ?? '')),
            'message' => trim((string) ($data['message'] ?? '')),
            'ip' => $request->getClientIp(),
            'userAgent' => $request->headers->get('User-Agent', ''),
            'requestedAt' => new \DateTimeImmutable(),
        ];

        try {
            $this->sendMailService->sendMail(
                'Orthogram',
                $this->supportEmail,
                'Demande d’aide pour identifiant oublié',
                'forgot_identifier_request',
                $context,
                null
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Erreur lors de l’envoi de la demande d’identifiant oublié au support.', [
                'supportEmail' => $this->supportEmail,
                'ip' => $request->getClientIp(),
                'exception' => $exception,
            ]);

            return false;
        }

        return true;
    }
}
