<?php

namespace App\Services;

use App\Entity\Quiz;
use App\Entity\QuizQuestion;
use App\Exception\InvalidQuizConfiguration;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Serializes editorial forms before binding, never writes attempts or lessons. */
final class QuizEditorialService
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly QuizConfigurationValidator $validator)
    {
    }

    /** @template T
     * @param callable(): T $action
     *
     * @return T
     */
    public function transaction(Quiz|QuizQuestion|null $entity, ?int $targetQuizId, callable $action): mixed
    {
        $connection = $this->em->getConnection();
        if ($connection->isTransactionActive()) {
            throw new \LogicException('La transaction éditoriale doit commencer avant toute modification.');
        }
        // A question may have moved since EasyAdmin loaded its entity. Read its
        // current parent before locking, then verify that parent with a current read.
        for ($retry = 0; $retry < 3; ++$retry) {
            $sourceId = $entity instanceof Quiz ? $entity->getId() : null;
            if ($entity instanceof QuizQuestion && null !== $entity->getId()) {
                $source = $connection->fetchOne('SELECT quiz_id FROM quiz_question WHERE id = ?', [$entity->getId()]);
                if (false === $source) {
                    throw new NotFoundHttpException('Cette question a été supprimée.');
                }
                $sourceId = (int) $source;
            }
            $ids = array_values(array_unique(array_filter([$sourceId, $targetQuizId], static fn ($id) => null !== $id)));
            sort($ids);
            $connection->beginTransaction();
            try {
                // Separate ordered locks prevent opposite moves from reversing lock order.
                foreach ($ids as $id) {
                    $quiz = $this->em->find(Quiz::class, $id);
                    if (null !== $quiz) {
                        $this->em->refresh($quiz, LockMode::PESSIMISTIC_WRITE);
                    }
                }
                if ($entity instanceof QuizQuestion && null !== $entity->getId()) {
                    $this->em->refresh($entity, LockMode::PESSIMISTIC_WRITE);
                    if ($entity->getQuiz()?->getId() !== $sourceId) {
                        $connection->rollBack();
                        continue;
                    }
                }
                if ([] !== $ids) {
                    // Fetch-join + refresh rebuilds even previously initialized collections.
                    // Locking reads see the latest commit under MySQL REPEATABLE READ.
                    $this->em->createQuery('SELECT q, question, answer FROM App\\Entity\\Quiz q LEFT JOIN q.questions question LEFT JOIN question.answers answer WHERE q.id IN (:ids)')
                        ->setParameter('ids', $ids)->setHint(Query::HINT_REFRESH, true)->setLockMode(LockMode::PESSIMISTIC_WRITE)->getResult();
                }
                $result = $action();
                // Do not use ORM wrapInTransaction: it flushes invalid form data implicitly.
                $connection->commit();

                return $result;
            } catch (\Throwable $error) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                $this->em->clear();
                throw $error;
            }
        }

        throw new ConflictHttpException('La question a été déplacée simultanément. Rechargez le formulaire.');
    }

    public function validate(Quiz|QuizQuestion $entity, ?Quiz $originalQuiz = null): void
    {
        $quizzes = $entity instanceof Quiz ? [$entity] : [$originalQuiz, $entity->getQuiz()];
        $seen = [];
        foreach ($quizzes as $quiz) {
            if (null === $quiz || isset($seen[spl_object_id($quiz)])) {
                continue;
            }
            $seen[spl_object_id($quiz)] = true;
            // A quota also constrains fixed attempts. Progressive preparation stays
            // available in fixed mode with no quota, including an empty initial set.
            if (!$quiz->isRenewalEnabled() && null === $quiz->getErrorQuestionQuota()) {
                continue;
            }
            try {
                $this->validator->validate($quiz);
            } catch (InvalidQuizConfiguration $error) {
                throw new InvalidQuizConfiguration('Quiz « '.$quiz->getTitle().' » : '.$error->getMessage(), $error->propertyPath, $error->questionId);
            }
        }
    }
}
