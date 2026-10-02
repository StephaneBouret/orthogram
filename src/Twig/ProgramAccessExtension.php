<?php

namespace App\Twig;

use App\Repository\ProgramRepository;
use App\Security\Voter\CourseVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Attribute\AsTwigFunction;

final class ProgramAccessExtension
{
    public function __construct(
        private readonly ProgramRepository $programRepository,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[AsTwigFunction('can_access_private_program')]
    public function canAccessPrivateProgram(string $slug): bool
    {
        $program = $this->programRepository->findOneBy(['slug' => $slug]);

        return null !== $program && $this->authorizationChecker->isGranted(CourseVoter::PROGRAM_VIEW, $program);
    }
}
