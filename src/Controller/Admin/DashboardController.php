<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        // return parent::index();

        // Option 1. You can make your dashboard redirect to some common page of your backend
        //
        // 1.2) Same example but using the "ugly URLs" that were used in previous EasyAdmin versions:
        $adminUrlGenerator = $this->container->get(AdminUrlGeneratorInterface::class);
        return $this->redirect($adminUrlGenerator->setController(ProgramCrudController::class)->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Orthogram')
            ->setLocales(['fr']);
    }

    public function configureMenuItems(): iterable
    {
        return [
            MenuItem::linkToDashboard('Dashboard', 'fa fa-home'),

            MenuItem::section('Entreprise'),
            MenuItem::linkTo(CompanyCrudController::class, 'Entreprise', 'fa-solid fa-building'),

            MenuItem::section('Formation'),
            MenuItem::linkTo(ProgramCrudController::class, 'Programme de formation', 'fas fa-list-check'),
            MenuItem::linkTo(SectionsCrudController::class, 'Sections du programme', 'fa-fw fas fa-section'),

            MenuItem::section('Site'),
            MenuItem::linkToUrl('Retour au site', 'fas fa-home', $this->generateUrl('app_home')),
        ];
    }
}
