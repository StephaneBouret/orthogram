<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\UserDevice;
use App\Enum\DeviceStatus;
use App\Repository\UserDeviceRepository;
use App\Services\DeviceService;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class UserDeviceCrudController extends AbstractCrudController
{
    public function __construct(private readonly AdminUrlGeneratorInterface $adminUrlGenerator)
    {
    }

    public static function getEntityFqcn(): string
    {
        return UserDevice::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityPermission('ROLE_ADMIN')
            ->setEntityLabelInSingular('Appareil utilisateur')
            ->setEntityLabelInPlural('Appareils utilisateurs')
            ->setPageTitle(Crud::PAGE_INDEX, 'Appareils utilisateurs')
            ->setPageTitle(Crud::PAGE_DETAIL, 'Détail de l\'appareil')
            ->setDefaultSort(['lastUsedAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            AssociationField::new('user', 'Utilisateur')
                ->formatValue(fn ($value, $entity) => $entity->getUser()?->getFullname() ?? 'Utilisateur inconnu'),
            TextField::new('deviceType', 'Type')
                ->formatValue(static function ($value): string {
                    return match ($value) {
                        'desktop' => 'Ordinateur',
                        'mobile' => 'Mobile',
                        'tablet' => 'Tablette',
                        default => $value ?: 'Inconnu',
                    };
                }),
            TextField::new('browser', 'Navigateur'),
            TextField::new('platform', 'OS'),
            TextField::new('lastIp', 'Dernière IP'),

            ChoiceField::new('status', 'Statut')
                ->setChoices(DeviceStatus::choices())
                ->renderAsBadges([
                    DeviceStatus::ACTIVE->value => 'success',
                    DeviceStatus::REVOKED->value => 'danger',
                ])
                ->hideOnForm(),

            DateTimeField::new('firstSeenAt', 'Première connexion')
                ->hideOnIndex(),

            DateTimeField::new('lastUsedAt', 'Dernière activité'),

            DateTimeField::new('revokedAt', 'Révoqué le')
                ->hideOnIndex(),

            TextField::new('deviceUuid', 'UUID appareil')
                ->hideOnIndex(),

            TextField::new('userAgentHash', 'Hash User-Agent')
                ->hideOnIndex(),

            DateTimeField::new('updatedAt', 'Modifié le')
                ->hideOnIndex(),
        ];
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('user')
            ->add('status')
            ->add('deviceType')
            ->add('browser')
            ->add('platform')
            ->add('lastIp')
            ->add('lastUsedAt')
            ->add('revokedAt');
    }

    public function configureActions(Actions $actions): Actions
    {
        $revokeDevice = Action::new('revokeDevice', 'Révoquer', 'fa fa-ban')
            ->linkToCrudAction('revokeDevice')
            ->displayIf(static fn (UserDevice $device): bool => $device->isActive())
            ->addCssClass('btn btn-danger');

        $activateDevice = Action::new('activateDevice', 'Activer', 'fa fa-check')
            ->linkToCrudAction('activateDevice')
            ->displayIf(static fn (UserDevice $device): bool => $device->isRevoked())
            ->addCssClass('btn btn-success');

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $revokeDevice)
            ->add(Crud::PAGE_INDEX, $activateDevice)
            ->add(Crud::PAGE_DETAIL, $revokeDevice)
            ->add(Crud::PAGE_DETAIL, $activateDevice)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    #[AdminRoute(path: '/revoke-device', name: 'revoke_device')]
    public function revokeDevice(
        AdminContext $context,
        UserDeviceRepository $userDeviceRepository,
        DeviceService $deviceService,
        TokenStorageInterface $tokenStorage,
    ): RedirectResponse {
        $device = $this->getDeviceFromContext($context, $userDeviceRepository);

        if (!$device instanceof UserDevice) {
            return $this->redirectToDeviceIndex();
        }

        if ($device->isRevoked()) {
            $this->addFlash('warning', 'Cet appareil est déjà révoqué.');

            return $this->redirectToDeviceIndex();
        }

        $isCurrentAdminDevice = $this->isCurrentUserDevice($device, $context, $deviceService);

        $deviceService->revokeDevice($device);

        if ($isCurrentAdminDevice) {
            $tokenStorage->setToken(null);

            if ($context->getRequest()->hasSession()) {
                $context->getRequest()->getSession()->invalidate();
            }

            $response = $this->redirectToRoute('app_login', [
                'device_revoked' => 1,
            ]);

            $response->headers->clearCookie(DeviceService::COOKIE_NAME);
            $response->headers->clearCookie('REMEMBERME');
            $response->headers->clearCookie('trusted_device');

            return $response;
        }

        $this->addFlash('success', 'L\'appareil a été révoqué.');

        return $this->redirectToDeviceIndex();
    }

    #[AdminRoute(path: '/activate-device', name: 'activate_device')]
    public function activateDevice(
        AdminContext $context,
        UserDeviceRepository $userDeviceRepository,
        DeviceService $deviceService,
    ): RedirectResponse {
        $device = $this->getDeviceFromContext($context, $userDeviceRepository);

        if (!$device instanceof UserDevice) {
            return $this->redirectToDeviceIndex();
        }

        if ($device->isActive()) {
            $this->addFlash('warning', 'Cet appareil est déjà actif.');

            return $this->redirectToDeviceIndex();
        }

        $user = $device->getUser();

        if (!$user instanceof User) {
            $this->addFlash('warning', 'Utilisateur introuvable pour cet appareil.');

            return $this->redirectToDeviceIndex();
        }

        if ($userDeviceRepository->countActiveForUser($user) >= DeviceService::MAX_ACTIVE_DEVICES) {
            $this->addFlash('warning', 'Cet utilisateur a déjà 2 appareils actifs.');

            return $this->redirectToDeviceIndex();
        }

        $deviceService->reactivateDevice($device);

        $this->addFlash('success', 'L\'appareil a été réactivé.');

        return $this->redirectToDeviceIndex();
    }

    private function getDeviceFromContext(
        AdminContext $context,
        UserDeviceRepository $userDeviceRepository,
    ): ?UserDevice {
        $entityId = $context->getRequest()->query->get('entityId');

        if (!$entityId) {
            $this->addFlash('warning', 'Appareil introuvable.');

            return null;
        }

        $device = $userDeviceRepository->find($entityId);

        if (!$device instanceof UserDevice) {
            $this->addFlash('warning', 'Appareil introuvable.');

            return null;
        }

        return $device;
    }

    private function isCurrentUserDevice(
        UserDevice $device,
        AdminContext $context,
        DeviceService $deviceService,
    ): bool {
        $currentUser = $this->getUser();

        return $currentUser instanceof User
            && $device->getUser()?->getId() === $currentUser->getId()
            && $device->getDeviceUuid() === $deviceService->getDeviceUuidFromCookie($context->getRequest());
    }

    private function redirectToDeviceIndex(): RedirectResponse
    {
        return $this->redirect($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->unset('entityId')
            ->generateUrl());
    }
}
