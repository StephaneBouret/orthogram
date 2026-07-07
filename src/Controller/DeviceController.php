<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserDevice;
use App\Repository\UserDeviceRepository;
use App\Services\DeviceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/mes-appareils', name: 'app_device_')]
#[IsGranted('ROLE_USER')]
final class DeviceController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        UserDeviceRepository $userDeviceRepository,
        DeviceService $deviceService,
    ): Response {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('device/index.html.twig', [
            'devices' => $userDeviceRepository->findActiveForUser($user),
            'current_device_uuid' => $deviceService->getDeviceUuidFromCookie($request),
        ]);
    }

    #[Route('/{id}/revoquer', name: 'revoke', methods: ['POST'])]
    public function revoke(
        UserDevice $device,
        Request $request,
        DeviceService $deviceService,
        TokenStorageInterface $tokenStorage,
    ): RedirectResponse {
        $user = $this->getUser();

        if (!$user instanceof User || $device->getUser()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('revoke_device_'.$device->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if (!$device->isActive()) {
            $this->addFlash('warning', 'Cet appareil est déjà révoqué.');

            return $this->redirectToRoute('app_device_index');
        }

        $isCurrentDevice = $device->getDeviceUuid() === $deviceService->getDeviceUuidFromCookie($request);

        $deviceService->revokeDevice($device);

        if ($isCurrentDevice) {
            $tokenStorage->setToken(null);

            if ($request->hasSession()) {
                $request->getSession()->invalidate();
            }

            $response = $this->redirectToRoute('app_login', [
                'device_revoked' => 1,
            ]);

            $response->headers->clearCookie(DeviceService::COOKIE_NAME);
            $response->headers->clearCookie('REMEMBERME');
            $response->headers->clearCookie('trusted_device');

            return $response;
        }

        $this->addFlash('success', 'Appareil révoqué avec succès.');

        return $this->redirectToRoute('app_device_index');
    }
}
