<?php

namespace App\Services;

/** Offline conservation checks. Raw snapshots must stay outside Git and the web root. */
final class ModuleTwoQuizSnapshot
{
    /** @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, mixed> $plan  post-operation preview, except for activation (reviewed pre-operation plan)
     *
     * @return array<string, mixed> */
    public function compare(array $before, array $after, string $phase, array $plan = []): array
    {
        ModuleTwoQuizManifest::require(in_array($phase, ['migration', 'import', 'enable', 'disable', 'identical'], true), 'Phase inconnue.');
        ModuleTwoQuizManifest::require($before['destination'] === $after['destination'], 'Destinations différentes.');
        $left = $before['tables'];
        $right = $after['tables'];
        $changes = [];
        if ('migration' === $phase) {
            if (!isset($left['quiz_manifest_identity'])) {
                ModuleTwoQuizManifest::require(isset($right['quiz_manifest_identity']) && [] === $right['quiz_manifest_identity']['rows'], 'Nouvelle table de traçabilité vide requise.');
                unset($right['quiz_manifest_identity']);
                $changes['new_table'] = 'quiz_manifest_identity';
            }
            $version = 'DoctrineMigrations\\Version20261027120000';
            $oldVersions = array_column($left['doctrine_migration_versions']['rows'], 'version');
            ModuleTwoQuizManifest::require(in_array($version, array_column($right['doctrine_migration_versions']['rows'], 'version'), true), 'Migration module 2 absente du journal.');
            if (!in_array($version, $oldVersions, true)) {
                $right['doctrine_migration_versions']['rows'] = array_values(array_filter($right['doctrine_migration_versions']['rows'], static fn ($r) => $r['version'] !== $version));
                $changes['migration'] = $version;
            }
        }
        if (in_array($phase, ['import', 'enable', 'disable'], true)) {
            ModuleTwoQuizManifest::require(($plan['destination'] ?? null) === $before['destination'] && [] === ($plan['errors'] ?? null) && [] === ($plan['differences'] ?? null), 'Plan absent, divergent ou destiné à une autre base.');
            ModuleTwoQuizManifest::require(array_keys($plan['quizzes']) === ['module2_test1', 'module2_test2', 'module2_general'], 'Trois identités module 2 requises.');
            $identities = array_column($after['tables']['quiz_manifest_identity']['rows'], null, 'quiz_key');
            $ids = [];
            foreach ($plan['quizzes'] as $key => $quiz) {
                ModuleTwoQuizManifest::require(isset($identities[$key]) && null !== $quiz['id'] && $identities[$key]['quiz_id'] === $quiz['id']
                    && $identities[$key]['manifest_hash'] === $plan['manifest_sha256'], 'Traçabilité divergente : '.$key);
                $ids[] = $quiz['id'];
            }
            ModuleTwoQuizManifest::require(3 === count(array_unique($ids)), 'Trois quiz distincts requis.');
            if ('import' === $phase) {
                ModuleTwoQuizManifest::require('import' === $plan['action'] && 0 === $plan['add_quizzes'] && 177 === $plan['existing_copies'], 'Prévisualisation complète après import requise.');
                $newIds = array_values(array_diff($ids, array_column($left['quiz']['rows'], 'id')));
                ModuleTwoQuizManifest::require(in_array(count($newIds), [0, 3], true), 'Import partiel.');
                $questions = array_filter($right['quiz_question']['rows'], static fn ($r) => in_array($r['quiz_id'], $newIds, true));
                $questionIds = array_column($questions, 'id');
                $filters = [
                    'quiz' => static fn ($r) => in_array($r['id'], $newIds, true),
                    'quiz_question' => static fn ($r) => in_array($r['quiz_id'], $newIds, true),
                    'quiz_answer' => static fn ($r) => in_array($r['question_id'], $questionIds, true),
                    'quiz_reserve_import' => static fn ($r) => in_array($r['quiz_id'], $newIds, true),
                    'quiz_manifest_identity' => static fn ($r) => in_array($r['quiz_id'], $newIds, true),
                ];
                foreach (['quiz' => 3, 'quiz_question' => 177, 'quiz_answer' => 354, 'quiz_reserve_import' => 177, 'quiz_manifest_identity' => 3] as $table => $count) {
                    $added = array_filter($right[$table]['rows'], $filters[$table]);
                    ModuleTwoQuizManifest::require(count($added) === ([] === $newIds ? 0 : $count), 'Volume ajouté divergent : '.$table);
                    $right[$table]['rows'] = array_values(array_filter($right[$table]['rows'], static fn ($r) => !$filters[$table]($r)));
                    // Only these insertion tables may advance their technical sequence.
                    foreach ([&$left, &$right] as &$tables) {
                        $tables[$table]['ddl'] = preg_replace('/ AUTO_INCREMENT=\d+/', '', $tables[$table]['ddl']);
                    }
                    unset($tables);
                    $changes[$table] = count($added);
                }
                foreach ($after['tables']['quiz']['rows'] as $quiz) {
                    if (in_array($quiz['id'], $newIds, true)) {
                        ModuleTwoQuizManifest::require(0 === (int) $quiz['renewal_enabled'] && null === $quiz['error_question_quota'], 'Nouveau quiz non fixe ou quota non NULL.');
                    }
                }
            } else {
                ModuleTwoQuizManifest::require($phase === $plan['action'], 'Action différente du plan.');
                foreach ($plan['renewal_changes'] as $change) {
                    ModuleTwoQuizManifest::require(in_array($change['quiz_id'], $ids, true) && 'renewal_enabled' === $change['field'] && $change['after'] === ('enable' === $phase), 'Changement non autorisé.');
                    $found = false;
                    foreach ($left['quiz']['rows'] as &$quiz) {
                        if ($quiz['id'] === $change['quiz_id']) {
                            ModuleTwoQuizManifest::require((bool) $quiz['renewal_enabled'] === $change['before'], 'État initial différent du plan.');
                            $quiz['renewal_enabled'] = (int) $change['after'];
                            $found = true;
                        }
                    }
                    unset($quiz);
                    ModuleTwoQuizManifest::require($found, 'Quiz initial absent.');
                }
                $changes = $plan['renewal_changes'];
            }
        }
        ModuleTwoQuizManifest::require(array_keys($left) === array_keys($right), 'Liste des tables différente.');
        foreach ($left as $table => $old) {
            $new = $right[$table];
            ModuleTwoQuizManifest::require($old['columns'] === $new['columns'] && $old['ddl'] === $new['ddl'], 'Schéma différent : '.$table);
            ModuleTwoQuizManifest::require($this->sorted($old['rows']) === $this->sorted($new['rows']), 'Données différentes : '.$table);
        }

        return ['success' => true, 'phase' => $phase, 'tables_checked' => count($left), 'changes' => $changes];
    }

    /** @param array<mixed> $rows
     * @return array<mixed> */
    private function sorted(array $rows): array
    {
        foreach ($rows as &$row) {
            ksort($row);
        }
        unset($row);
        usort($rows, static fn ($a, $b) => strcmp(serialize($a), serialize($b)));

        return $rows;
    }
}
