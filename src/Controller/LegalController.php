<?php

namespace App\Controller;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LegalController extends AbstractController
{
    public function __construct(private CompanyRepository $companyRepository)
    {
    }

    #[Route('/mentions-legales', name: 'app_legal', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderLegalPage('legal/legal.html.twig');
    }

    #[Route('/politique-de-confidentialite', name: 'app_privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->renderLegalPage('legal/privacy.html.twig');
    }

    #[Route('/conditions-generales-de-vente', name: 'app_terms', methods: ['GET'])]
    public function terms(): Response
    {
        return $this->renderLegalPage('legal/terms.html.twig');
    }

    private function renderLegalPage(string $template): Response
    {
        return $this->render($template, [
            'company' => $this->getCompany(),
        ]);
    }

    private function getCompany(): Company
    {
        $company = $this->companyRepository->findOneBy([], ['id' => 'ASC']);

        if (null === $company) {
            throw $this->createNotFoundException('Aucune entreprise n\'est configurée pour les pages légales.');
        }

        return $company;
    }
}
