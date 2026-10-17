<?php

declare(strict_types=1);

namespace App\Controller\Course;

use App\Entity\Dictation;
use App\Entity\DictationAttempt;
use App\Entity\User;
use App\Enum\DictationAction;
use App\Enum\DictationState;
use App\Security\Voter\CourseVoter;
use App\Services\Dictation\DictationAttemptService;
use App\Services\Dictation\DictationAudioStorage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dictees', name: 'app_dictation_')]
final class DictationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DictationAttemptService $attempts,
        private readonly DictationAudioStorage $storage,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:DICTATION_LEARNER_ENABLED)%')] private readonly bool $enabled,
        private readonly ?Profiler $profiler = null,
    ) {
    }

    #[Route('/{id}', name: 'entry', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function entry(int $id): Response
    {
        return $this->respond(function () use ($id): Response {
            $actor = $this->actor();
            $dictation = $this->dictation($id);
            $attempt = $this->em->getRepository(DictationAttempt::class)->findOneBy(['user' => $actor, 'dictation' => $dictation]);
            if (null !== $attempt) {
                return $this->redirectToRoute('app_dictation_write', ['id' => $attempt->getId()]);
            }

            return $this->render('dictation/entry.html.twig', ['dictationId' => $id]);
        });
    }

    #[Route('/{id}/commencer', name: 'start', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function start(int $id, Request $request): Response
    {
        return $this->respond(function () use ($id, $request): Response {
            $actor = $this->actor();
            $this->dictation($id);
            $this->csrf('dictation_start_'.$id, $request->request->get('_token'));
            $data = $this->attempts->start($actor, $id, true);

            return $this->redirectToRoute('app_dictation_write', ['id' => $data['attemptId']], Response::HTTP_SEE_OTHER);
        });
    }

    #[Route('/tentatives/{id}', name: 'write', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function write(int $id): Response
    {
        return $this->respond(function () use ($id): Response {
            $actor = $this->actor();
            $this->owned($id, $actor);

            return $this->render('dictation/write.html.twig', ['writing' => $this->attempts->state($actor, $id, true)]);
        });
    }

    #[Route('/tentatives/{id}/enregistrer', name: 'save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(int $id, Request $request): Response
    {
        return $this->respond(function () use ($id, $request): Response {
            $actor = $this->actor();
            $this->owned($id, $actor);
            $this->csrf('dictation_save_'.$id, $request->headers->get('X-CSRF-TOKEN'));
            // Bound decoding too; escaped Unicode can occupy six JSON bytes per UTF-16 unit.
            $body = $request->getContent();
            if (strlen($body) > 125000) {
                throw new HttpException(413, 'Copie trop longue. Récupérez votre texte avant de le raccourcir.');
            }
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data) || 2 !== count($data) || !isset($data['revision'], $data['copy'])
                || !is_int($data['revision']) || $data['revision'] < 0 || !is_string($data['copy'])) {
                throw new \InvalidArgumentException('La demande doit contenir uniquement la révision et la copie.');
            }
            $saved = $this->attempts->transition($actor, $id, DictationAction::Save, $data['revision'], $data['copy'], true);

            // No echo of a possibly superseded draft, no generic workflow projection.
            return new JsonResponse(['revision' => $saved['revision'], 'wordCount' => $saved['wordCount'], 'referenceWordCount' => $saved['referenceWordCount']]);
        });
    }

    #[Route('/tentatives/{id}/audio/{role}', name: 'audio', requirements: ['id' => '\d+', 'role' => 'complete|writing'], methods: ['GET', 'HEAD'])]
    public function audio(int $id, string $role): Response
    {
        return $this->respond(function () use ($id, $role): Response {
            $attempt = $this->owned($id, $this->actor());
            $audio = 'complete' === $role ? $attempt->getVersion()->getCompleteAudio() : $attempt->getVersion()->getWritingAudio();
            if (null === $audio) {
                throw new HttpException(404, 'Piste indisponible.');
            }
            $response = new BinaryFileResponse($this->storage->resolve($audio), 200, ['Content-Type' => 'audio/mpeg'], false);
            $response->setContentDisposition('inline', 'dictee.mp3');

            return $response;
        });
    }

    private function actor(): User
    {
        if (!$this->enabled) {
            throw new HttpException(404, 'Page indisponible.');
        }
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw new HttpException(401, 'Votre session a expiré. Récupérez votre texte puis reconnectez-vous.');
        }
        if (!$actor->isAccountActive()) {
            throw new HttpException(403, 'Ce compte ne permet pas cet accès.');
        }

        return $actor;
    }

    private function dictation(int $id): Dictation
    {
        $dictation = $this->em->find(Dictation::class, $id);
        if (null === $dictation) {
            throw new HttpException(404, 'Dictée introuvable.');
        }
        if (!$this->isGranted(CourseVoter::SECTION_VIEW, $dictation->getSection())) {
            throw new HttpException(403, 'Votre accès à la formation ne permet pas cette dictée.');
        }

        return $dictation;
    }

    private function owned(int $id, User $actor): DictationAttempt
    {
        $attempt = $this->em->getRepository(DictationAttempt::class)->findOneBy(['id' => $id, 'user' => $actor]);
        if (null === $attempt) {
            throw new HttpException(404, 'Tentative introuvable.');
        }
        $this->dictation($attempt->getDictation()->getId());
        if (DictationState::Writing !== $attempt->getState()) {
            throw new HttpException(409, 'Cette tentative n’est plus dans la phase d’écriture initiale.');
        }

        return $attempt;
    }

    private function csrf(string $id, mixed $token): void
    {
        if (!is_string($token) || !$this->isCsrfTokenValid($id, $token)) {
            throw new HttpException(403, 'Session ou jeton expiré. Récupérez votre texte puis rechargez la page.');
        }
    }

    /** Prevent exception/debug pages from disclosing editorial data in writing responses. */
    private function respond(callable $operation): Response
    {
        $this->profiler?->disable();
        try {
            $response = $operation();
        } catch (HttpExceptionInterface $error) {
            $response = new JsonResponse(['message' => $error->getMessage()], $error->getStatusCode());
        } catch (\InvalidArgumentException|\JsonException $error) {
            $response = new JsonResponse(['message' => $error instanceof \JsonException ? 'Demande JSON invalide.' : $error->getMessage()], 422);
        } catch (\Throwable $error) {
            $this->logger->error('Dictation writing request failed.', ['exception' => $error]);
            $response = new JsonResponse(['message' => 'Enregistrement ou lecture indisponible. Conservez votre texte et réessayez.'], 503);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
