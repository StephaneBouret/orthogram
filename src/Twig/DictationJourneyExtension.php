<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Courses;
use App\Entity\Sections;
use App\Entity\User;
use App\Enum\DictationState;
use App\Security\Voter\CourseVoter;
use App\Services\Dictation\DictationJourney;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

final class DictationJourneyExtension
{
    public function __construct(private readonly DictationJourney $journey, private readonly Security $security, private readonly UrlGeneratorInterface $urls)
    {
    }

    /** @return array{url: string, label: string, afterLabel: string, closed: bool, completed: bool, locked: bool}|null */
    #[AsTwigFunction('dictation_journey')]
    public function section(Sections $section): ?array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->security->isGranted(CourseVoter::SECTION_VIEW, $section)) {
            return null;
        }
        $journey = $this->journey->forSection($user, $section);
        if (null === $journey) {
            return null;
        }
        $attempt = $journey['attempt'];
        $state = $attempt?->getState();

        return [
            'url' => null === $attempt ? $this->urls->generate('app_dictation_entry', ['id' => $journey['dictation']->getId()])
                : $this->urls->generate('app_dictation_write', ['id' => $attempt->getId()]),
            'label' => match ($state) {
                null => 'Commencer la dictée',
                DictationState::Writing => 'Poursuivre l’écriture',
                DictationState::SelfCorrection => 'Poursuivre l’autocorrection',
                default => 'Dictée initiale clôturée',
            },
            'afterLabel' => match ($state) {
                DictationState::Completed => 'Consulter le résultat définitif',
                DictationState::Reviewing => 'Consulter ma copie et finaliser',
                DictationState::Resuming => 'Poursuivre ma dictée après les cours',
                default => ($attempt?->closedWithoutErrors() ?? false) ? 'Retrouver ma copie sans erreur' : 'Reprendre ma dictée après les cours',
            },
            'closed' => $attempt?->isPrerequisiteSatisfied() ?? false,
            'completed' => DictationState::Completed === $state,
            'locked' => $journey['locked'],
        ];
    }

    #[AsTwigFunction('dictation_course_prerequisite')]
    public function prerequisite(Courses $course): ?string
    {
        if ($course->isFreeLesson() || null === $course->getSection()) {
            return null;
        }
        $journey = $this->section($course->getSection());

        return ($journey['locked'] ?? false) ? $journey['url'] : null;
    }
}
