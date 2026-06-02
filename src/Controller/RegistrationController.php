<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Google\GoogleService;
use App\Repository\InvitationRepository;
use App\Services\InvitationService;
use App\Services\UserRegistrationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    #[Route('/inscription', name: 'app_register', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        RequestStack $requestStack,
        UserRegistrationService $userRegistrationService,
        Security $security,
        GoogleService $googleService,
        InvitationRepository $invitationRepository,
        InvitationService $invitationService
    ): Response {
        // Gestion propre du _target_path
        $session = $requestStack->getSession();
        $targetPath = $request->query->get('_target_path', $session->get('_security.main.target_path'));

        if ($targetPath) {
            $session->set('_security.main.target_path', $targetPath);
        }

        $invitationToken = $request->query->get('invitation');
        $invitation = null;

        if ($invitationToken) {
            $invitation = $invitationRepository->findValidByToken($invitationToken);
        }

        $user = new User();

        if ($invitation) {
            $user->setEmail((string) $invitation->getEmail());
        }

        $form = $this->createForm(RegistrationFormType::class, $user, [
            'invitation_email' => $invitation?->getEmail(),
            'lock_email' => null !== $invitation,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $plainPassword */
            $plainPassword = (string) $form->get('plainPassword')->getData();

            if ($invitation) {
                $user->setEmail((string) $invitation->getEmail());
            }

            $userRegistrationService->register($user, $plainPassword);

            if ($invitation) {
                $invitationService->consumeInvitation($invitation, $user);
            }

            // 🔥 Symfony gère la redirection automatiquement
            return $security->login($user, 'security.authenticator.form_login.main', 'main');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
            'google_api_key' => $googleService->getGoogleKey(),
            'invitation' => $invitation,
        ]);
    }
}
