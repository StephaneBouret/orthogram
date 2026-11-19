<?php

namespace App\Services;

use Doctrine\ORM\EntityManagerInterface;

final class ModuleThreeQuizImporter extends EditorialQuizImporter
{
    public function __construct(EntityManagerInterface $em, ModuleThreeQuizManifest $manifest, QuizConfigurationValidator $validator,
        QuizEditorialService $editorial, ModuleThreeQuizDestination $destination)
    {
        parent::__construct($em, $manifest, $validator, $editorial, $destination);
    }

    protected function lockPrefix(): string
    {
        return 'quiz-m3-';
    }

    public function plan(string $action, array $context): array
    {
        $this->verifyContext($action, $context);

        return parent::plan($action, $context);
    }

    protected function checkAdditionalConflicts(array &$report): void
    {
        $db = $this->em->getConnection();
        $keys = array_keys($report['quizzes']);
        foreach ($db->fetchFirstColumn("SELECT quiz_key FROM quiz_manifest_identity WHERE quiz_key LIKE 'module3\\_%' ORDER BY quiz_key") as $key) {
            if (!in_array($key, $keys, true)) {
                $this->difference($report, $key, null, 'unexpected_quiz_key', $keys, $key);
            }
        }
        $expected = [];
        foreach ($this->manifest->data['copies'] as $copy) {
            $expected[$copy['source_key']][] = $report['quizzes'][$copy['quiz_key']]['question_ids'][$copy['source_key']] ?? null;
        }
        $byText = [];
        foreach ($this->manifest->sources() as $key => $source) {
            if (isset($expected[$key])) {
                $byText[$source['candidate']['text']] = $key;
            }
        }
        $matches = $db->fetchAllAssociative('SELECT id, quiz_id, text FROM quiz_question WHERE BINARY text IN (?) ORDER BY id',
            [array_keys($byText)], [\Doctrine\DBAL\ArrayParameterType::STRING]);
        foreach ($matches as $row) {
            $key = $byText[$row['text']];
            if (!in_array((int) $row['id'], $expected[$key], true)) {
                $this->difference($report, 'module3', $key, 'content_collision', array_values(array_filter($expected[$key])), $row);
            }
        }
        $tables = ['quiz', 'quiz_question', 'quiz_answer', 'quiz_reserve_import', 'quiz_manifest_identity'];
        $unsupported = $db->fetchFirstColumn("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN (?) AND engine <> 'InnoDB' ORDER BY table_name",
            [$tables], [\Doctrine\DBAL\ArrayParameterType::STRING]);
        if ([] !== $unsupported) {
            $report['errors'][] = 'Tables non transactionnelles : '.implode(', ', $unsupported);
        }
    }
}
