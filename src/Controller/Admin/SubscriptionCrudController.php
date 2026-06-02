<?php

namespace App\Controller\Admin;

use App\Entity\Subscription;
use App\Enum\SubscriptionStatus;
use App\Repository\SubscriptionRepository;
use App\Services\SubscriptionAdminActionService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SubscriptionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Subscription::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            // ->setEntityPermission('ROLE_ADMIN')
            ->setEntityLabelInSingular('Abonnement')
            ->setEntityLabelInPlural('Abonnements')
            ->setPageTitle(Crud::PAGE_INDEX, 'Abonnements')
            ->setPageTitle(Crud::PAGE_DETAIL, 'Détail de l\'abonnement')
            ->setPageTitle(Crud::PAGE_EDIT, 'Modifier l\'abonnement')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            AssociationField::new('user', 'Utilisateur')
                ->setFormTypeOption('disabled', true),
            EmailField::new('email', 'Email')
                ->setFormTypeOption('disabled', true),
            TextField::new('title', 'Titre')
                ->hideOnForm(),
            TextareaField::new('description', 'Description')
                ->hideOnIndex()
                ->hideOnForm(),
            TextField::new('effectiveStatusLabel', 'Statut')
                ->formatValue(fn ($value, Subscription $subscription): string => $this->formatStatusBadge($subscription))
                ->renderAsHtml()
                ->setSortable(false)
                ->hideOnForm(),
            MoneyField::new('priceCents', 'Prix')
                ->setCurrency('EUR')
                ->setStoredAsCents()
                ->setFormTypeOption('disabled', true),
            BooleanField::new('isLifetime', 'À vie')
                ->renderAsSwitch(false)
                ->hideOnForm(),
            DateTimeField::new('startsAt', 'Début')
                ->setFormTypeOption('disabled', true),
            DateTimeField::new('endsAt', 'Fin')
                ->setFormTypeOption('disabled', true),
            TextField::new('paymentReference', 'Référence paiement')
                ->hideOnForm(),
            DateTimeField::new('reminder30SentAt', 'Relance J-30 envoyée')
                ->hideOnForm(),
            DateTimeField::new('reminder15SentAt', 'Relance J-15 envoyée')
                ->hideOnForm(),
            DateTimeField::new('termsAcceptedAt', 'CGV acceptées')
                ->hideOnIndex()
                ->hideOnForm(),
            DateTimeField::new('immediateAccessRequestedAt', 'Accès immédiat demandé')
                ->hideOnIndex()
                ->hideOnForm(),
            DateTimeField::new('withdrawalRightWaivedAt', 'Rétractation renoncée')
                ->hideOnIndex()
                ->hideOnForm(),
            DateTimeField::new('createdAt', 'Créé le')
                ->hideOnForm(),
            DateTimeField::new('updatedAt', 'Modifié le')
                ->hideOnForm()
                ->hideOnIndex(),
        ];
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('email')
            ->add('status')
            ->add('isLifetime')
            ->add('startsAt')
            ->add('endsAt')
            ->add('createdAt');
    }

    public function configureActions(Actions $actions): Actions
    {
        $grantFreeYear = Action::new('grantFreeYear', 'Offrir 1 an', 'fa fa-gift')
            ->linkToCrudAction('grantFreeYear')
            ->displayIf(static function (Subscription $subscription): bool {
                $status = $subscription->getEffectiveStatus();

                return $status === SubscriptionStatus::ACTIVE
                    || $status === SubscriptionStatus::EXPIRED
                    || $status === SubscriptionStatus::SUSPENDED;
            })
            ->addCssClass('btn btn-info');

        $grantLifetime = Action::new('grantLifetime', 'Offrir à vie', 'fa fa-infinity')
            ->linkToCrudAction('grantLifetime')
            ->displayIf(static function (Subscription $subscription): bool {
                $status = $subscription->getEffectiveStatus();

                return $status === SubscriptionStatus::ACTIVE
                    || $status === SubscriptionStatus::EXPIRED
                    || $status === SubscriptionStatus::SUSPENDED;
            })
            ->addCssClass('btn btn-success');

        $suspendSubscription = Action::new('suspendSubscription', 'Suspendre', 'fa fa-pause')
            ->linkToCrudAction('suspendSubscription')
            ->displayIf(static function (Subscription $subscription): bool {
                return $subscription->getEffectiveStatus() === SubscriptionStatus::ACTIVE;
            })
            ->addCssClass('btn btn-warning');

        $cancelSubscription = Action::new('cancelSubscription', 'Annuler', 'fa fa-ban')
            ->linkToCrudAction('cancelSubscription')
            ->displayIf(static function (Subscription $subscription): bool {
                $status = $subscription->getEffectiveStatus();

                return $status === SubscriptionStatus::ACTIVE
                    || $status === SubscriptionStatus::SUSPENDED;
            })
            ->addCssClass('btn btn-danger');

        $reactivateSubscription = Action::new('reactivateSubscription', 'Réactiver', 'fa fa-play')
            ->linkToCrudAction('reactivateSubscription')
            ->displayIf(static function (Subscription $subscription): bool {
                return $subscription->getEffectiveStatus() === SubscriptionStatus::SUSPENDED
                    && (
                        $subscription->isLifetime()
                        || $subscription->getEndsAt() === null
                        || $subscription->getEndsAt() >= new \DateTimeImmutable()
                    );
            })
            ->addCssClass('btn btn-success');

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, $grantFreeYear)
            ->add(Crud::PAGE_EDIT, $grantLifetime)
            ->add(Crud::PAGE_EDIT, $suspendSubscription)
            ->add(Crud::PAGE_EDIT, $cancelSubscription)
            ->add(Crud::PAGE_EDIT, $reactivateSubscription)
            ->disable(Action::NEW);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof Subscription) {
            return;
        }

        if ($entityInstance->isLifetime()) {
            $entityInstance->setEndsAt(null);

            parent::updateEntity($entityManager, $entityInstance);

            return;
        }

        if (!$entityInstance->getEndsAt()) {
            $referenceDate = $entityInstance->getStartsAt()
                ?? $entityInstance->getCreatedAt()
                ?? new \DateTimeImmutable();

            $entityInstance->setEndsAt(
                $referenceDate->modify('+1 year')
            );
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    #[AdminRoute(path: '/grant-free-year', name: 'grant_free_year')]
    public function grantFreeYear(
        AdminContext $context,
        SubscriptionRepository $subscriptionRepository,
        SubscriptionAdminActionService $subscriptionAdminActionService
    ): RedirectResponse {
        $subscription = $this->getSubscriptionFromContext($context, $subscriptionRepository);

        if (!$subscription instanceof Subscription) {
            return $this->redirectToSubscriptionIndex();
        }

        try {
            $emailSent = $subscriptionAdminActionService->grantFreeYear($subscription);
        } catch (\RuntimeException $e) {
            $this->addFlash('warning', $e->getMessage());
            return $this->redirectToSubscriptionIndex();
        }

        $this->addFlash('success', 'L\'abonnement a été basculé en gratuité d\'un an.');
        $this->addNotificationFlash($emailSent);

        return $this->redirectToSubscriptionIndex();
    }

    #[AdminRoute(path: '/grant-lifetime', name: 'grant_lifetime')]
    public function grantLifetime(
        AdminContext $context,
        SubscriptionRepository $subscriptionRepository,
        SubscriptionAdminActionService $subscriptionAdminActionService
    ): RedirectResponse {
        $subscription = $this->getSubscriptionFromContext($context, $subscriptionRepository);

        if (!$subscription instanceof Subscription) {
            return $this->redirectToSubscriptionIndex();
        }

        try {
            $emailSent = $subscriptionAdminActionService->grantLifetime($subscription);
        } catch (\RuntimeException $e) {
            $this->addFlash('warning', $e->getMessage());
            return $this->redirectToSubscriptionIndex();
        }

        $this->addFlash('success', 'L\'abonnement a été basculé en gratuité à vie.');
        $this->addNotificationFlash($emailSent);

        return $this->redirectToSubscriptionIndex();
    }

    #[AdminRoute(path: '/suspend-subscription', name: 'suspend_subscription')]
    public function suspendSubscription(
        AdminContext $context,
        SubscriptionRepository $subscriptionRepository,
        SubscriptionAdminActionService $subscriptionAdminActionService
    ): RedirectResponse {
        $subscription = $this->getSubscriptionFromContext($context, $subscriptionRepository);

        if (!$subscription instanceof Subscription) {
            return $this->redirectToSubscriptionIndex();
        }

        try {
            $emailSent = $subscriptionAdminActionService->suspend($subscription);
        } catch (\RuntimeException $e) {
            $this->addFlash('warning', $e->getMessage());
            return $this->redirectToSubscriptionIndex();
        }

        $this->addFlash('success', 'L\'abonnement a été suspendu.');
        $this->addNotificationFlash($emailSent);

        return $this->redirectToSubscriptionIndex();
    }

    #[AdminRoute(path: '/cancel-subscription', name: 'cancel_subscription')]
    public function cancelSubscription(
        AdminContext $context,
        SubscriptionRepository $subscriptionRepository,
        SubscriptionAdminActionService $subscriptionAdminActionService
    ): RedirectResponse {
        $subscription = $this->getSubscriptionFromContext($context, $subscriptionRepository);

        if (!$subscription instanceof Subscription) {
            return $this->redirectToSubscriptionIndex();
        }

        try {
            $emailSent = $subscriptionAdminActionService->cancel($subscription);
        } catch (\RuntimeException $e) {
            $this->addFlash('warning', $e->getMessage());
            return $this->redirectToSubscriptionIndex();
        }

        $this->addFlash('success', 'L\'abonnement a été annulé.');
        $this->addNotificationFlash($emailSent);

        return $this->redirectToSubscriptionIndex();
    }

    #[AdminRoute(path: '/reactivate-subscription', name: 'reactivate_subscription')]
    public function reactivateSubscription(
        AdminContext $context,
        SubscriptionRepository $subscriptionRepository,
        SubscriptionAdminActionService $subscriptionAdminActionService
    ): RedirectResponse {
        $subscription = $this->getSubscriptionFromContext($context, $subscriptionRepository);

        if (!$subscription instanceof Subscription) {
            return $this->redirectToSubscriptionIndex();
        }

        try {
            $emailSent = $subscriptionAdminActionService->reactivate($subscription);
        } catch (\RuntimeException $e) {
            $this->addFlash('warning', $e->getMessage());
            return $this->redirectToSubscriptionIndex();
        }

        $this->addFlash('success', 'L\'abonnement a été réactivé.');
        $this->addNotificationFlash($emailSent);

        return $this->redirectToSubscriptionIndex();
    }

    private function getSubscriptionFromContext(
        AdminContext $context,
        SubscriptionRepository $subscriptionRepository
    ): ?Subscription {
        $entityId = $context->getRequest()->query->get('entityId');

        if (!$entityId) {
            $this->addFlash('warning', 'Abonnement introuvable.');

            return null;
        }

        $subscription = $subscriptionRepository->find($entityId);

        if (!$subscription instanceof Subscription) {
            $this->addFlash('warning', 'Abonnement introuvable.');

            return null;
        }

        return $subscription;
    }

    private function formatStatusBadge(Subscription $subscription): string
    {
        $status = $subscription->getEffectiveStatus();
        $class = match ($status) {
            SubscriptionStatus::PENDING => 'info',
            SubscriptionStatus::ACTIVE => 'success',
            SubscriptionStatus::EXPIRED => 'danger',
            SubscriptionStatus::CANCELLED => 'secondary',
            SubscriptionStatus::SUSPENDED => 'warning',
        };

        return sprintf(
            '<span class="badge badge-%s">%s</span>',
            $class,
            mb_strtoupper($status->label())
        );
    }

    private function redirectToSubscriptionIndex(): RedirectResponse
    {
        return $this->redirectToRoute('admin_subscription_index');
    }

    private function addNotificationFlash(bool $emailSent): void
    {
        if ($emailSent) {
            $this->addFlash('success', 'Un email automatique a été envoyé à l\'utilisateur.');

            return;
        }

        $this->addFlash('warning', 'Action effectuée, mais l\'email automatique n\'a pas pu être envoyé.');
    }
}
