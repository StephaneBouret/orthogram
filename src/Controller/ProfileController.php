<?php

namespace App\Controller;

use App\Entity\Avatar;
use App\Entity\User;
use App\Form\AvatarFormType;
use App\Form\UpdatePasswordUserFormType;
use App\Form\UpdateUserProfileFormType;
use App\Services\AvatarService;
use App\Services\UserProfileService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

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

        $profileForm = $this->createForm(UpdateUserProfileFormType::class, $user);
        $profileForm->handleRequest($request);

        if ($profileForm->isSubmitted() && $profileForm->isValid()) {
            $profileService->updateProfile($user);

            $this->addFlash('success', 'Vos informations ont bien été mises à jour.');

            return $this->redirectToRoute('app_profile');
        }

        $passwordForm = $this->createForm(UpdatePasswordUserFormType::class, $user);
        $passwordForm->handleRequest($request);

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

    #[Route('/profil/supprimer', name: 'app_profile_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        UserProfileService $profileService,
        TokenStorageInterface $tokenStorage,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('delete-profile' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Le jeton de sécurité est invalide.');

            return $this->redirectToRoute('app_profile');
        }

        if (mb_strtoupper(trim((string) $request->request->get('confirm'))) !== 'SUPPRIMER') {
            $this->addFlash('warning', 'Merci de confirmer la suppression du compte.');

            return $this->redirectToRoute('app_profile');
        }

        $tokenStorage->setToken(null);
        $profileService->deleteAccount($user);
        $request->getSession()->invalidate();

        $this->addFlash('success', 'Votre compte a bien été supprimé.');

        return $this->redirectToRoute('app_home', [], Response::HTTP_SEE_OTHER);
    }
}
