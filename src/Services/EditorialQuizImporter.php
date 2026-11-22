<?php

namespace App\Services;

use App\Entity\Quiz;
use App\Entity\QuizManifestIdentity;
use App\Entity\QuizReserveImport;
use Doctrine\ORM\EntityManagerInterface;

abstract class EditorialQuizImporter
{
    public function __construct(protected readonly EntityManagerInterface $em, protected readonly EditorialQuizManifest $manifest,
        private readonly QuizConfigurationValidator $validator, private readonly QuizEditorialService $editorial,
        protected readonly EditorialQuizDestination $destination)
    {
    }

    abstract protected function lockPrefix(): string;

    /** @param array<string, mixed> $context
     * @param array<string, mixed> $options */
    protected function verifyContext(string $action, array $context, array $options = []): void
    {
        foreach (['environment' => 'env', 'database' => 'database', 'host' => 'host', 'port' => 'port', 'server' => 'server'] as $field => $option) {
            $options['expect-'.$option] = (string) ($context[$field] ?? '');
        }
        $actual = $this->destination->verify($this->em->getConnection(), $options, $action);
        EditorialQuizManifest::require($actual === $context, 'Destination modifiée depuis la prévisualisation.');
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed> */
    public function plan(string $action, array $context): array
    {
        EditorialQuizManifest::require(in_array($action, ['import', 'enable', 'disable'], true), 'Action invalide.');
        $db = $this->em->getConnection();
        $schema = $db->createSchemaManager();
        $missing = [];
        foreach (['quiz', 'quiz_question', 'quiz_answer', 'quiz_reserve_import', 'quiz_manifest_identity'] as $table) {
            if (!$schema->tablesExist([$table])) {
                $missing[] = $table;
            }
        }
        foreach (['quiz' => ['renewal_enabled', 'error_question_quota'], 'quiz_question' => ['reserve', 'contains_error']] as $table => $fields) {
            if (!in_array($table, $missing, true)) {
                $columns = $schema->listTableColumns($table);
                foreach ($fields as $field) {
                    if (!isset($columns[$field])) {
                        $missing[] = $table.'.'.$field;
                    }
                }
            }
        }
        $report = ['action' => $action, 'destination' => $context, 'manifest_sha256' => $this->manifest->hash,
            'counts' => $this->manifest->data['counts'], 'held_sources' => $this->manifest->heldSourceKeys(),
            'schema_missing' => $missing, 'quizzes' => [], 'differences' => [], 'configuration_differences' => [], 'errors' => [],
            'add_quizzes' => 0, 'add_questions' => 0, 'add_answers' => 0, 'existing_copies' => 0, 'renewal_changes' => []];
        if ([] !== $missing) {
            $report['errors'][] = 'Migrations requises séparément : '.implode(', ', $missing);
        }
        $module = $this->manifest->data['module_key'];
        $keys = array_column($this->manifest->data['quizzes'], 'quiz_key');
        $registered = 0;
        foreach ($this->manifest->data['quizzes'] as $spec) {
            $key = $spec['quiz_key'];
            // Preview validates all reserves through the real Symfony validator too.
            $future = $this->manifest->build($spec)->setRenewalEnabled(true);
            $this->validator->validate($future);
            $identity = !in_array('quiz_manifest_identity', $missing, true) ? $db->fetchAssociative('SELECT * FROM quiz_manifest_identity WHERE quiz_key = ?', [$key]) : false;
            $id = false !== $identity && null !== $identity['quiz_id'] ? (int) $identity['quiz_id'] : null;
            $report['quizzes'][$key] = ['id' => $id, 'title' => $spec['title'], 'initial' => $spec['expected_initial_count'], 'reserve' => $spec['expected_reserve_count'], 'themes' => $spec['themes'] ?? $spec['theme_matrix']];
            $collisions = !in_array('quiz', $missing, true) ? $db->fetchFirstColumn('SELECT id FROM quiz WHERE title = ? ORDER BY id', [$spec['title']]) : [];
            $collisions = array_values(array_filter($collisions, static fn ($other) => (int) $other !== $id));
            if ([] !== $collisions) {
                $this->difference($report, $key, null, 'title_collision', [], $collisions);
            }
            if (false === $identity) {
                ++$report['add_quizzes'];
                $report['add_questions'] += $future->getQuestions()->count();
                foreach ($future->getQuestions() as $question) {
                    $report['add_answers'] += $question->getAnswers()->count();
                }
                if ('import' !== $action) {
                    $report['errors'][] = $key.' : import complet requis avant activation/désactivation.';
                }
                continue;
            }
            ++$registered;
            if ($identity['manifest_hash'] !== $this->manifest->hash) {
                $this->difference($report, $key, null, 'manifest_hash', $this->manifest->hash, $identity['manifest_hash']);
            }
            if (null === $id) {
                $this->difference($report, $key, null, 'quiz_id', 'quiz conservé', null);
                continue;
            }
            if ([] !== $missing) {
                continue;
            }
            $quiz = $db->fetchAssociative('SELECT * FROM quiz WHERE id = ?', [$id]);
            if (false === $quiz) {
                $this->difference($report, $key, null, 'quiz_id', $id, null);
                continue;
            }
            if ($quiz['title'] !== $spec['title']) {
                $this->difference($report, $key, null, 'title', $spec['title'], $quiz['title']);
            }
            $report['quizzes'][$key]['renewal_enabled'] = (bool) $quiz['renewal_enabled'];
            $report['quizzes'][$key]['error_question_quota'] = $quiz['error_question_quota'];
            if ($quiz['renewal_enabled'] || null !== $quiz['error_question_quota']) {
                $report['configuration_differences'][$key] = ['renewal_enabled' => (bool) $quiz['renewal_enabled'], 'error_question_quota' => $quiz['error_question_quota'], 'policy' => 'preserved_on_import'];
            }
            if ('import' !== $action) {
                if ((bool) $quiz['renewal_enabled'] !== ('enable' === $action)) {
                    $report['renewal_changes'][] = ['quiz_key' => $key, 'quiz_id' => $id, 'field' => 'renewal_enabled', 'before' => (bool) $quiz['renewal_enabled'], 'after' => 'enable' === $action];
                }
                $future->setErrorQuestionQuota($quiz['error_question_quota']);
                try {
                    $this->validator->validate($future);
                } catch (\App\Exception\InvalidQuizConfiguration $error) {
                    $report['errors'][] = $key.' : '.$error->getMessage();
                }
            }
            $traces = $db->fetchAllAssociative('SELECT * FROM quiz_reserve_import WHERE quiz_id = ? ORDER BY source_key', [$id]);
            $report['existing_copies'] += count($traces);
            $traces = array_column($traces, null, 'source_key');
            $known = [];
            foreach ($this->manifest->copies($key) as $copy) {
                $source = $copy['source_key'];
                $trace = $traces[$source] ?? null;
                unset($traces[$source]);
                if (null === $trace) {
                    $this->difference($report, $key, $source, 'trace', 'présente', null);
                    continue;
                }
                // The identity already reports a new edition once per quiz. Each trace must
                // still belong to its registered edition, even when reviewing a revised file.
                foreach (['manifest_hash' => $identity['manifest_hash'], 'payload_hash' => $copy['payload_sha256']] as $field => $expected) {
                    if ($trace[$field] !== $expected) {
                        $this->difference($report, $key, $source, $field, $expected, $trace[$field]);
                    }
                }
                $question = null !== $trace['question_id'] ? $db->fetchAssociative('SELECT * FROM quiz_question WHERE id = ?', [$trace['question_id']]) : false;
                if (false === $question) {
                    $this->difference($report, $key, $source, 'question_id', 'question conservée', null);
                    continue;
                }
                $known[] = (int) $question['id'];
                $report['quizzes'][$key]['question_ids'][$source] = (int) $question['id'];
                if ((int) $question['quiz_id'] !== $id) {
                    $this->difference($report, $key, $source, 'quiz_id', $id, $question['quiz_id']);
                }
                $actual = array_intersect_key($question, array_flip(['title', 'text', 'explanation', 'theme', 'multiple', 'contains_error', 'position', 'reserve']));
                foreach (['multiple', 'reserve', 'contains_error'] as $field) {
                    $actual[$field] = null === $actual[$field] ? null : (bool) $actual[$field];
                }
                $actual['position'] = (int) $actual['position'];
                $actual['answers'] = $db->fetchAllAssociative('SELECT content, correct, position FROM quiz_answer WHERE question_id = ? ORDER BY position, id', [$question['id']]);
                foreach ($actual['answers'] as &$answer) {
                    $answer['correct'] = (bool) $answer['correct'];
                    $answer['position'] = (int) $answer['position'];
                }
                unset($answer);
                foreach ($this->manifest->payload($copy) as $field => $expected) {
                    if (EditorialQuizManifest::hash([$expected]) !== EditorialQuizManifest::hash([$actual[$field]])) {
                        $this->difference($report, $key, $source, $field, $expected, $actual[$field]);
                    }
                }
            }
            foreach ($traces as $source => $trace) {
                $this->difference($report, $key, $source, 'foreign_trace', null, $trace);
            }
            $actualIds = array_map('intval', $db->fetchFirstColumn('SELECT id FROM quiz_question WHERE quiz_id = ? ORDER BY id', [$id]));
            $extra = array_values(array_diff($actualIds, $known));
            if ([] !== $extra) {
                $this->difference($report, $key, null, 'untracked_questions', [], $extra);
            }
        }
        if ($registered > 0 && count($keys) !== $registered) {
            $report['errors'][] = 'Import partiel : '.$registered.'/'.count($keys).' identités présentes.';
        }
        if ([] === $missing) {
            $orphan = $db->fetchAllAssociative(
                'SELECT t.quiz_id, t.source_key FROM quiz_reserve_import t LEFT JOIN quiz_manifest_identity i ON i.quiz_id = t.quiz_id WHERE t.source_key LIKE ? AND (i.quiz_key IS NULL OR i.quiz_key NOT IN (?)) ORDER BY t.quiz_id, t.source_key',
                [addcslashes($module, '_%\\').'\_docx\_%', $keys], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::STRING]
            );
            if ([] !== $orphan) {
                $this->difference($report, $module, null, 'orphan_traces', [], $orphan);
            }
            $this->checkAdditionalConflicts($report);
        }
        if ([] !== $report['differences']) {
            $report['errors'][] = 'Divergences détectées ; aucune écriture permise.';
        }
        $report['plan_hash'] = EditorialQuizManifest::hash($report);

        return $report;
    }

    /** @param array<string, mixed> $report */
    protected function checkAdditionalConflicts(array &$report): void
    {
    }

    /** @param array<string, mixed> $report */
    protected function difference(array &$report, string $quiz, ?string $source, string $field, mixed $expected, mixed $actual): void
    {
        $report['differences'][] = ['quiz_key' => $quiz, 'source_key' => $source, 'field' => $field, 'expected' => $expected, 'actual' => $actual];
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed> */
    public function apply(string $action, string $expectedPlan, array $context, bool $allowWorkDatabase = false, bool $allowProductionDatabase = false): array
    {
        $db = $this->em->getConnection();
        $options = ['apply' => true, 'allow-work-database' => $allowWorkDatabase, 'allow-production-database' => $allowProductionDatabase];
        $this->verifyContext($action, $context, $options);
        EditorialQuizManifest::require(!$db->isTransactionActive(), 'Transaction externe interdite.');
        // A connection-scoped lock protects identities that do not exist yet.
        // The PK and UNIQUE constraints are the persistent second line of defence.
        $lock = $this->lockPrefix().hash('sha1', $context['database']);
        EditorialQuizManifest::require(1 === (int) $db->fetchOne('SELECT GET_LOCK(?, 15)', [$lock]), 'Import concurrent : verrou indisponible.');
        try {
            $ids = $db->fetchFirstColumn('SELECT quiz_id FROM quiz_manifest_identity WHERE quiz_key IN (?) AND quiz_id IS NOT NULL ORDER BY quiz_id',
                [array_column($this->manifest->data['quizzes'], 'quiz_key')], [\Doctrine\DBAL\ArrayParameterType::STRING]);

            return $this->editorial->transaction(null, null, function () use ($action, $expectedPlan, $context): array {
                $plan = $this->plan($action, $context);
                EditorialQuizManifest::require(hash_equals($expectedPlan, $plan['plan_hash']), 'Plan périmé sous verrou ; refaire la prévisualisation.');
                EditorialQuizManifest::require([] === $plan['errors'], implode("\n", $plan['errors']));
                if (('import' === $action && 0 === $plan['add_quizzes']) || ('import' !== $action && [] === $plan['renewal_changes'])) {
                    return ['applied' => true, 'noop' => true, 'questions_added' => 0, 'answers_added' => 0, 'quizzes_changed' => 0, 'plan_hash' => $expectedPlan];
                }
                foreach ($this->manifest->data['quizzes'] as $spec) {
                    $key = $spec['quiz_key'];
                    if ('import' === $action) {
                        $quiz = $this->manifest->build($spec);
                        $this->em->persist($quiz);
                        $this->em->persist(new QuizManifestIdentity($key, $quiz, $this->manifest->hash));
                        foreach ($this->manifest->copies($key) as $index => $copy) {
                            // Existing trace semantics support initials: source/quiz, not role, is its key.
                            $this->em->persist(new QuizReserveImport($quiz, $copy['source_key'], $quiz->getQuestions()->get($index), $copy['payload_sha256'], $this->manifest->hash));
                        }
                    } else {
                        $quiz = $this->em->find(Quiz::class, $plan['quizzes'][$key]['id']);
                        $quiz->setRenewalEnabled('enable' === $action);
                    }
                    $mode = $quiz->isRenewalEnabled();
                    $quiz->setRenewalEnabled(true);
                    try {
                        $this->validator->validate($quiz);
                    } finally {
                        $quiz->setRenewalEnabled($mode);
                    }
                }
                $this->em->flush();

                return ['applied' => true, 'noop' => false, 'questions_added' => 'import' === $action ? $plan['add_questions'] : 0,
                    'answers_added' => 'import' === $action ? $plan['add_answers'] : 0, 'quizzes_changed' => count($plan['renewal_changes']), 'plan_hash' => $expectedPlan];
            }, array_map('intval', $ids));
        } finally {
            $db->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
