<?php

declare(strict_types=1);

namespace App\Services\Dictation;

use App\Entity\Dictation;
use App\Entity\DictationAttempt;
use App\Entity\DictationVersion;
use App\Entity\Sections;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** Read-only pedagogical facts, never an authorization or a workflow transition.
 * @phpstan-type Journey array{dictation: Dictation, attempt: ?DictationAttempt, locked: bool}
 */
final class DictationJourney
{
    private ?Request $cachedRequest = null;
    /** @var array<string, Journey|null> */
    private array $cache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(bool:DICTATION_LEARNER_ENABLED)%')] private readonly bool $enabled,
        private readonly ?RequestStack $requests = null,
    ) {
    }

    /** @return array{dictation: Dictation, attempt: ?DictationAttempt, locked: bool}|null */
    public function forSection(User $user, Sections $section): ?array
    {
        if (!$this->enabled || null === $user->getId() || null === $section->getId()) {
            return null;
        }
        $request = $this->requests?->getCurrentRequest();
        if ($request !== $this->cachedRequest) {
            $this->cache = [];
            $this->cachedRequest = $request;
        }
        $key = $user->getId().':'.$section->getId();
        if (null !== $request && array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        $result = $this->read($user, $section);
        if (null !== $request) {
            $this->cache[$key] = $result;
        }

        return $result;
    }

    /** @return Journey|null */
    private function read(User $user, Sections $section): ?array
    {
        $dictation = $this->em->getRepository(Dictation::class)->findOneBy(['section' => $section]);
        if (null === $dictation) {
            return null;
        }
        $attempt = $this->em->getRepository(DictationAttempt::class)->findOneBy(['user' => $user, 'dictation' => $dictation]);
        if (null === $attempt) {
            // The most recent published version is exactly the one start() will use.
            $version = $this->em->getRepository(DictationVersion::class)->createQueryBuilder('v')
                ->where('v.dictation = :dictation AND v.publishedAt IS NOT NULL')->setParameter('dictation', $dictation)
                ->orderBy('v.number', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
            if (null === $version || null === $version->getCompleteAudio() || null === $version->getWritingAudio() || [] === $version->getSegments()) {
                return null;
            }
        }

        return ['dictation' => $dictation, 'attempt' => $attempt,
            'locked' => !in_array('ROLE_ADMIN', $user->getRoles(), true)
                && in_array($dictation->getId(), $user->getDictationPrerequisites() ?? [], true)
                && !($attempt?->isPrerequisiteSatisfied() ?? false)];
    }

    public function isLocked(User $user, Sections $section): bool
    {
        return $this->forSection($user, $section)['locked'] ?? false;
    }
}
