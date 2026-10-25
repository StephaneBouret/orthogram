<?php

namespace App\Services;

use App\Entity\Quiz;
use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;
use App\Entity\QuizReserveImport;
use Doctrine\ORM\EntityManagerInterface;

final class ModuleOneReserveImporter
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly QuizEditorialService $editorial,
        private readonly QuizConfigurationValidator $validator, private readonly ModuleOneReserveManifest $manifest)
    {
    }

    /** @return array<string, Quiz> */
    public function resolve(): array
    {
        $result = [];
        foreach ([...$this->manifest->data['fundamentals'], ...$this->manifest->data['quizzes']] as $spec) {
            $course = $spec['course'];
            // Confirmed beta title compatibility; keep the provenance manifest unchanged.
            $titles = 'fundamentals_4' === $spec['quiz_key']
                ? ['Test général : réviser les fondamentaux', 'Test : réviser les fondamentaux']
                : [$spec['expected_title']];
            $placeholders = implode(', ', array_fill(0, count($titles), '?'));
            $ids = $this->em->getConnection()->fetchFirstColumn('SELECT q.id FROM quiz q JOIN courses c ON c.quiz_id = q.id JOIN sections s ON s.id = c.section_id JOIN program p ON p.id = s.program_id WHERE q.title IN ('.$placeholders.') AND c.slug = ? AND s.slug = ? AND p.slug = ?',
                [...$titles, $course['slug'], $course['section_slug'], $course['program_slug']]);
            ModuleOneReserveManifest::require(1 === count($ids), 'Résolution ambiguë ou impossible : '.$spec['quiz_key']);
            $quiz = $this->em->find(Quiz::class, (int) $ids[0]);
            ModuleOneReserveManifest::require(null !== $quiz && in_array($quiz->getTitle(), $titles, true), 'Identité du quiz différente.');
            ModuleOneReserveManifest::require(1 === $quiz->getCourses()->count(), 'Rattachements multiples : '.$spec['quiz_key']);
            $result[$spec['quiz_key']] = $quiz;
        }
        ModuleOneReserveManifest::require(8 === count(array_unique(array_map(static fn ($q) => $q->getId(), $result))), 'Quiz cibles confondus.');

        return $result;
    }

    /** @param array<string, mixed> $destination
     * @return array<string, mixed> */
    public function plan(string $action = 'import', array $destination = []): array
    {
        ModuleOneReserveManifest::require(in_array($action, ['audit', 'import', 'enable', 'disable'], true), 'Action inconnue.');
        $quizzes = $this->resolve();
        $report = ['action' => $action, 'manifest_sha256' => $this->manifest->hash, 'sources' => 47, 'quizzes' => [], 'initial_differences' => [], 'errors' => [],
            'initial_metadata_changes' => [], 'schema_ready' => $this->em->getConnection()->createSchemaManager()->tablesExist(['quiz_reserve_import'])];
        if ([] !== $destination) {
            $report['destination'] = $destination;
        }
        $report['targets'] = array_combine(array_column($this->manifest->data['quizzes'], 'quiz_key'),
            array_map(static fn ($spec) => $quizzes[$spec['quiz_key']]->getId(), $this->manifest->data['quizzes']));
        foreach ([...$this->manifest->data['fundamentals'], ...$this->manifest->data['quizzes']] as $spec) {
            $quiz = $quizzes[$spec['quiz_key']];
            $initial = $quiz->getInitialQuestions();
            $report['quizzes'][$spec['quiz_key']] = ['id' => $quiz->getId(), 'title' => $quiz->getTitle(), 'fixed' => !$quiz->isRenewalEnabled(),
                'initial_count' => count($initial), 'reserve_count' => $quiz->getReserveQuestionCount(), 'quota' => $quiz->getErrorQuestionQuota(),
                'course_ids' => array_map(static fn ($c) => $c->getId(), $quiz->getCourses()->toArray()),
                'initial_ids' => array_map(static fn ($q) => $q->getId(), $initial),
                'initial_hash' => self::hash(array_map(self::payload(...), $initial))];
            if (count($initial) !== $spec['expected_initial_count']) {
                $report['errors'][] = $spec['quiz_key'].' : nombre initial différent.';
            }
            foreach ($spec['initial_questions'] ?? [] as $index => $reference) {
                $actual = isset($initial[$index]) ? self::payload($initial[$index]) : [];
                unset($actual['reserve'], $actual['contains_error']);
                foreach ($reference['expected_existing_payload'] as $field => $expected) {
                    if (($actual[$field] ?? null) !== $expected) {
                        $report['initial_differences'][] = ['quiz_key' => $spec['quiz_key'], 'index' => $index, 'field' => $field, 'reference' => $expected, 'actual' => $actual[$field] ?? null];
                    }
                }
            }
        }
        $db = $this->em->getConnection();
        $report['totals'] = ['quizzes' => (int) $db->fetchOne('SELECT COUNT(*) FROM quiz'), 'questions' => (int) $db->fetchOne('SELECT COUNT(*) FROM quiz_question'),
            'answers' => (int) $db->fetchOne('SELECT COUNT(*) FROM quiz_answer'), 'initial' => (int) $db->fetchOne('SELECT COUNT(*) FROM quiz_question WHERE reserve = 0')];
        $existing = 0;
        $report['copies'] = [];
        foreach ($this->manifest->data['quizzes'] as $spec) {
            $quiz = $quizzes[$spec['quiz_key']];
            if ('import' === $action && $quiz->isRenewalEnabled()) {
                $report['errors'][] = $spec['quiz_key'].' : import limité au mode fixe.';
            }
            if (null !== $quiz->getErrorQuestionQuota()) {
                $report['errors'][] = $spec['quiz_key'].' : quota existant à examiner, aucune remise à NULL automatique.';
            }
            if ($report['schema_ready']) {
                $keys = $db->fetchFirstColumn('SELECT source_key FROM quiz_reserve_import WHERE quiz_id = ?', [$quiz->getId()]);
                if (array_diff($keys, $spec['source_keys'])) {
                    $report['errors'][] = $spec['quiz_key'].' : traces étrangères au manifeste.';
                }
            }
            $position = max(array_map(static fn ($q) => $q->getPosition(), $quiz->getInitialQuestions()) ?: [-1]) + 1;
            $themes = array_map(static fn ($q) => $q->getTheme(), $quiz->getInitialQuestions());
            $known = [];
            foreach ($spec['source_keys'] as $key) {
                $source = $this->manifest->sources()[$key];
                $expected = $source['candidate'];
                unset($expected['corrected_text']);
                $expected['theme'] = $source['theme'];
                $expected['position'] = $position++;
                if (!in_array($source['theme'], $themes, true)) {
                    $report['errors'][] = $key.' : thème absent du jeu initial réel.';
                }
                $entry = $report['schema_ready'] ? $db->fetchAssociative('SELECT question_id, payload_hash, manifest_hash FROM quiz_reserve_import WHERE quiz_id = ? AND source_key = ?', [$quiz->getId(), $key]) : false;
                $question = null;
                if (false !== $entry) {
                    foreach ($quiz->getQuestions() as $candidate) {
                        if ($candidate->getId() === $entry['question_id']) {
                            $question = $candidate;
                            break;
                        }
                    }
                }
                if (false !== $entry) {
                    ++$existing;
                    if (null === $question || $question->getQuiz()?->getId() !== $quiz->getId()
                        || self::hash(self::payload($question)) !== self::hash($expected)
                        || $entry['payload_hash'] !== self::hash($expected) || $entry['manifest_hash'] !== $this->manifest->hash) {
                        $report['errors'][] = $spec['quiz_key'].'/'.$key.' : provenance ou contenu divergent (question supprimée/déplacée/modifiée).';
                    }
                    if (null !== $question) {
                        $known[] = $question->getId();
                    }
                }
                foreach ($quiz->getQuestions() as $other) {
                    if ($other !== $question && trim($other->getText() ?? '') === trim($expected['text'])) {
                        $report['errors'][] = $spec['quiz_key'].'/'.$key.' : collision avec la question '.$other->getId();
                    }
                }
                $report['copies'][] = ['quiz_key' => $spec['quiz_key'], 'quiz_id' => $quiz->getId(), 'source_key' => $key, 'payload' => $expected, 'existing_question_id' => $question?->getId()];
            }
            foreach ($quiz->getQuestions() as $question) {
                if ($question->isReserve() && !in_array($question->getId(), $known, true)) {
                    $report['errors'][] = $spec['quiz_key'].' : réserve non tracée '.$question->getId();
                }
            }
        }
        if ($existing > 0 && $existing < 94) {
            $report['errors'][] = 'Import partiel : '.$existing.'/94 traces présentes.';
        }
        $report['existing_copies'] = $existing;
        $report['add_questions'] = 0 === $existing ? 94 : 0;
        $report['add_answers'] = 2 * $report['add_questions'];
        $report['expected_totals'] = ['questions' => $report['totals']['questions'] + $report['add_questions'], 'answers' => $report['totals']['answers'] + $report['add_answers']];
        if ('enable' === $action && 94 !== $existing) {
            $report['errors'][] = 'Activation après import complet uniquement.';
        }
        if ('disable' === $action) {
            $report['errors'] = [];
        } // Rollback starts with configuration, never deletion of history.
        $report['plan_hash'] = self::hash($report);

        return $report;
    }

    /** @param array<string, mixed> $destination
     * @return array<string, mixed> */
    public function apply(string $action, string $expectedPlan, array $destination = []): array
    {
        ModuleOneReserveManifest::require(in_array($action, ['import', 'enable', 'disable'], true), 'Action d’écriture invalide.');
        $quizzes = $this->resolve();
        $ids = array_map(static fn ($spec) => (int) $quizzes[$spec['quiz_key']]->getId(), $this->manifest->data['quizzes']);

        return $this->editorial->transaction(null, null, function () use ($action, $expectedPlan, $destination): array {
            // Resolve and inspect again after all quiz locks, never trust a preview.
            $plan = $this->plan($action, $destination);
            if ([] !== $destination || 'import' !== $action || 94 !== $plan['existing_copies']) {
                ModuleOneReserveManifest::require(hash_equals($expectedPlan, $plan['plan_hash']), 'La prévisualisation a changé sous verrou ; refaire le rapport.');
            }
            ModuleOneReserveManifest::require([] === $plan['errors'], implode("\n", $plan['errors']));
            if ([] !== $destination && 'disable' !== $action) {
                ModuleOneReserveManifest::require([] === $plan['initial_differences'], 'Différences sur les initiales : examiner le rapport avant toute écriture en production.');
            }
            ModuleOneReserveManifest::require($plan['schema_ready'] || 'disable' === $action, 'Migration de traçabilité requise sur la destination.');
            $quizzes = $this->resolve();
            if ('import' === $action) {
                if (0 === $plan['existing_copies']) {
                    foreach ($plan['copies'] as $copy) {
                        $quiz = $quizzes[$copy['quiz_key']];
                        $p = $copy['payload'];
                        $question = (new QuizQuestion())->setQuiz($quiz)->setTitle($p['title'])->setText($p['text'])->setExplanation($p['explanation'])
                            ->setMultiple($p['multiple'])->setTheme($p['theme'])->setPosition($p['position'])->setReserve(true)->setContainsError($p['contains_error']);
                        foreach ($p['answers'] as $a) {
                            $question->addAnswer((new QuizAnswer())->setContent($a['content'])->setCorrect($a['correct'])->setPosition($a['position']));
                        }
                        $this->em->persist($question);
                        $this->em->persist(new QuizReserveImport($quiz, $copy['source_key'], $question, self::hash($p), $this->manifest->hash));
                    }
                }
            } else {
                foreach ($this->manifest->data['quizzes'] as $spec) {
                    $quizzes[$spec['quiz_key']]->setRenewalEnabled('enable' === $action);
                }
            }
            foreach ($this->manifest->data['quizzes'] as $spec) {
                $quiz = $quizzes[$spec['quiz_key']];
                $this->editorial->validate($quiz);
                if ('import' === $action) {
                    // Validate the future renewable bank without changing the persisted mode.
                    $quiz->setRenewalEnabled(true);
                    try {
                        $this->validator->validate($quiz);
                    } finally {
                        $quiz->setRenewalEnabled(false);
                    }
                }
            }
            $this->em->flush();

            return ['action' => $action, 'applied' => true, 'questions_added' => 'import' === $action ? $plan['add_questions'] : 0,
                'answers_added' => 'import' === $action ? $plan['add_answers'] : 0, 'plan_hash' => $plan['plan_hash']];
        }, $ids);
    }

    /** @return array<string, mixed> */
    public static function payload(QuizQuestion $q): array
    {
        $answers = $q->getAnswers()->toArray();
        usort($answers, static fn ($a, $b) => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

        return ['title' => $q->getTitle(), 'text' => $q->getText(), 'explanation' => $q->getExplanation(), 'multiple' => $q->isMultiple(),
            'position' => $q->getPosition(), 'theme' => $q->getTheme(), 'reserve' => $q->isReserve(), 'contains_error' => $q->getContainsError(),
            'answers' => array_map(static fn ($a) => ['content' => $a->getContent(), 'correct' => $a->isCorrect(), 'position' => $a->getPosition()], $answers)];
    }

    /** @param array<mixed> $data */
    public static function hash(array $data): string
    {
        $sort = static function (array &$value) use (&$sort): void {
            if (!array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $sort($item);
                }
            }
        };
        $sort($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
