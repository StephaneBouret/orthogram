<?php

namespace App\Controller;

use App\Repository\InvitationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/invitation')]
final class InvitationController extends AbstractController
{
    #[Route('/{token}', name: 'app_invitation_claim', methods: ['GET'])]
    public function __invoke(
        string $token,
        InvitationRepository $invitationRepository
    ): Response {
        $invitation = $invitationRepository->findValidByToken($token);

        if (!$invitation) {
            $this->addFlash('warning', 'Cette invitation est introuvable, expirée ou déjà utilisée.');
            return $this->redirectToRoute('app_home');
        }

        return $this->redirectToRoute('app_register', [
            'invitation' => $invitation->getToken(),
        ]);
    }
}
