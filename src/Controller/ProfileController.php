<?php

namespace App\Controller;

use App\Entity\Avatar;
use App\Entity\User;
use App\Form\AvatarFormType;
use App\Form\UpdatePasswordUserFormType;
use App\Form\UpdateUserProfileFormType;
use App\Services\AvatarService;
use App\Services\DeviceService;
use App\Services\UserEmailChangeService;
use App\Services\UserProfileService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER', message: 'Vous devez être connecté pour accéder à cette page.')]
final class ProfileController extends AbstractController
{
    #[Route('/profil', name: 'app_profile', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        AvatarService $avatarService,
        UserProfileService $profileService,
        UserEmailChangeService $emailChangeService,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $currentEmail = (string) $user->getEmail();

        $avatar = $user->getAvatar() ?? new Avatar($user);
        $avatarForm = $this->createForm(AvatarFormType::class, $avatar, [
            'allow_delete' => false,
            'image_uri' => false,
        ]);
        $avatarForm->handleRequest($request);

        if ($avatarService->handleAvatarForm($avatarForm, $user, $avatar)) {
            $this->addFlash('success', 'Votre avatar a bien été mis à jour.');

            return $this->redirectToRoute('app_profile');
        }

        $passwordForm = $this->createForm(UpdatePasswordUserFormType::class, $user);
        $passwordForm->handleRequest($request);

        $profileForm = $this->createForm(UpdateUserProfileFormType::class, $user, [
            'current_email' => $currentEmail,
        ]);
        $profileForm->handleRequest($request);

        if ($profileForm->isSubmitted() && $profileForm->isValid()) {
            $requestedEmail = $emailChangeService->normalizeEmail((string) $profileForm->get('email')->getData());
            $emailChanged = $requestedEmail !== $emailChangeService->normalizeEmail($currentEmail);
            $profileHasErrors = false;

            if ($emailChanged) {
                $emailChangePassword = (string) $profileForm->get('emailChangePassword')->getData();

                if ('' === trim($emailChangePassword)) {
                    $profileForm->get('emailChangePassword')->addError(new FormError('Merci de confirmer le changement d\'identifiant avec votre mot de passe actuel.'));
                    $profileHasErrors = true;
                } elseif (!$passwordHasher->isPasswordValid($user, $emailChangePassword)) {
                    $profileForm->get('emailChangePassword')->addError(new FormError('Le mot de passe actuel est incorrect.'));
                    $profileHasErrors = true;
                }

                try {
                    $emailChangeService->assertEmailCanBeRequested($user, $requestedEmail);
                } catch (\InvalidArgumentException $exception) {
                    $profileForm->get('email')->addError(new FormError($exception->getMessage()));
                    $profileHasErrors = true;
                }
            }

            if ($profileHasErrors) {
                return $this->render('profile/edit.html.twig', [
                    'user' => $user,
                    'avatarForm' => $avatarForm,
                    'profileForm' => $profileForm,
                    'passwordForm' => $passwordForm,
                ]);
            }

            $profileService->updateProfile($user);

            if ($emailChanged) {
                try {
                    $emailChangeService->requestEmailChange($user, $requestedEmail);
                    $this->addFlash('success', 'Vos informations ont bien été mises à jour. Un email de confirmation vient d\'être envoyé à votre nouvelle adresse.');
                } catch (\RuntimeException $exception) {
                    $profileForm->get('email')->addError(new FormError($exception->getMessage()));

                    return $this->render('profile/edit.html.twig', [
                        'user' => $user,
                        'avatarForm' => $avatarForm,
                        'profileForm' => $profileForm,
                        'passwordForm' => $passwordForm,
                    ]);
                }
            } else {
                $this->addFlash('success', 'Vos informations ont bien été mises à jour.');
            }

            return $this->redirectToRoute('app_profile');
        }

        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            $profileService->updatePassword($user, (string) $passwordForm->get('newPassword')->getData());

            $this->addFlash('success', 'Votre mot de passe a bien été mis à jour.');

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/edit.html.twig', [
            'user' => $user,
            'avatarForm' => $avatarForm,
            'profileForm' => $profileForm,
            'passwordForm' => $passwordForm,
        ]);
    }

    #[Route('/profil/email/confirmer/{token}', name: 'app_profile_email_confirm', methods: ['GET'])]
    public function confirmEmailChange(string $token, UserEmailChangeService $emailChangeService): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $result = $emailChangeService->confirmEmailChange($user, $token);

        match ($result) {
            'confirmed' => $this->addFlash('success', 'Votre identifiant de connexion a bien été modifié.'),
            'expired' => $this->addFlash('warning', 'Le lien de confirmation a expiré. Merci de refaire une demande.'),
            'unavailable' => $this->addFlash('danger', 'Cette adresse email est déjà utilisée par un autre compte.'),
            default => $this->addFlash('danger', 'Le lien de confirmation est invalide.'),
        };

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/profil/email/annuler', name: 'app_profile_email_cancel', methods: ['POST'])]
    public function cancelEmailChange(Request $request, UserEmailChangeService $emailChangeService): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('cancel-email-change'.$user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Le jeton de sécurité est invalide.');

            return $this->redirectToRoute('app_profile');
        }

        $emailChangeService->cancelEmailChange($user);
        $this->addFlash('success', 'La demande de changement d\'identifiant a été annulée.');

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/profil/supprimer', name: 'app_profile_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        UserProfileService $profileService,
        TokenStorageInterface $tokenStorage,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('delete-profile'.$user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Le jeton de sécurité est invalide.');

            return $this->redirectToRoute('app_profile');
        }

        if ('SUPPRIMER' !== mb_strtoupper(trim((string) $request->request->get('confirm')))) {
            $this->addFlash('warning', 'Merci de confirmer la suppression du compte.');

            return $this->redirectToRoute('app_profile');
        }

        $tokenStorage->setToken(null);
        $profileService->deleteAccount($user);
        $request->getSession()->invalidate();

        $this->addFlash('success', 'Votre compte a bien été supprimé.');

        $response = $this->redirectToRoute('app_home', [], Response::HTTP_SEE_OTHER);
        $response->headers->clearCookie(DeviceService::COOKIE_NAME);
        $response->headers->clearCookie('REMEMBERME');
        $response->headers->clearCookie('trusted_device');

        return $response;
    }
}
