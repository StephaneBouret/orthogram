<?php

namespace App\Controller;

use App\Form\ForgotIdentifierRequestFormType;
use App\Form\ResetPasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use App\Repository\UserRepository;
use App\Services\ForgotIdentifierRequestService;
use App\Services\PasswordResetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route(path: '/login', name: 'app_login')]
    public function login(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();

        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'device_limit_error' => $request->query->getBoolean('device_limit'),
            'device_revoked' => $request->query->getBoolean('device_revoked'),
        ]);
    }

    #[Route('/identifiant-oublie', name: 'app_forgot_identifier', methods: ['GET', 'POST'])]
    public function forgotIdentifier(Request $request, ForgotIdentifierRequestService $forgotIdentifierRequestService): Response
    {
        $form = $this->createForm(ForgotIdentifierRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            $data['website'] = $form->get('website')->getData();

            if ($forgotIdentifierRequestService->notifySupport($data, $request)) {
                $this->addFlash('success', 'Si les informations transmises permettent d’identifier un compte, une réponse vous sera envoyée.');

                return $this->redirectToRoute('app_login');
            }

            $form->addError(new FormError('Votre demande n’a pas pu être envoyée pour le moment. Merci de réessayer dans quelques instants.'));
        }

        return $this->render('security/forgot_identifier.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route('/forgot-password', name: 'app_forgot_pw')]
    public function forgotPw(Request $request, UserRepository $userRepository, PasswordResetService $passwordResetService): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $userRepository->findOneBy([
                'email' => $form->get('email')->getData(),
            ]);

            if ($user) {
                $passwordResetService->processPasswordReset($user);
            }

            $this->addFlash('success', 'Si un compte existe avec cet email, un message de réinitialisation vient d\'être envoyé.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_password_request.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/forgot-password/{token}', name: 'app_reset_pw')]
    public function resetPw(string $token, Request $request, UserPasswordHasherInterface $hasher, PasswordResetService $passwordResetService): Response
    {
        $user = $passwordResetService->getUserByResetToken($token);

        if (!$user) {
            $this->addFlash('danger', 'Jeton invalide');

            return $this->redirectToRoute('app_login');
        }

        if ($passwordResetService->isTokenExpired($user)) {
            $this->addFlash('warning', 'Votre demande de mot de passe a expiré. Merci de la renouveller.');

            return $this->redirectToRoute('app_forgot_pw');
        }

        $form = $this->createForm(ResetPasswordFormType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = (string) $form->get('plainPassword')->getData();

            if ($hasher->isPasswordValid($user, $plainPassword)) {
                $form->get('plainPassword')->get('first')->addError(
                    new FormError('Le nouveau mot de passe doit être différent de l\'actuel.')
                );

                return $this->render('security/reset_password.html.twig', [
                    'passForm' => $form,
                ]);
            }

            $passwordResetService->updatePassword($user, $plainPassword);
            $this->addFlash('success', 'Mot de passe changé avec succès');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_password.html.twig', [
            'passForm' => $form,
        ]);
    }
}
