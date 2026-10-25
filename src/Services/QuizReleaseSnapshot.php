<?php

namespace App\Services;

use Doctrine\DBAL\Connection;

/** Read-only evidence; compare never connects to a database or repairs differences. */
final class QuizReleaseSnapshot
{
    /** @return array<string, mixed> */
    public function capture(Connection $db): array
    {
        $db->executeStatement('SET TRANSACTION READ ONLY');
        $db->beginTransaction();
        try {
            $tables = [];
            foreach ($db->fetchAllNumeric('SHOW FULL TABLES') as [$table, $type]) {
                ModuleOneReserveManifest::require('BASE TABLE' === $type, 'Vue à examiner avant capture : '.$table);
                $quoted = $db->quoteIdentifier($table);
                $rows = $db->fetchAllAssociative('SELECT * FROM '.$quoted);
                foreach ($rows as &$row) {
                    ksort($row);
                } unset($row);
                usort($rows, static fn ($a, $b) => strcmp(serialize($a), serialize($b)));
                $tables[$table] = ['rows' => $rows, 'columns' => $db->fetchAllAssociative('SHOW FULL COLUMNS FROM '.$quoted), 'ddl' => $db->fetchNumeric('SHOW CREATE TABLE '.$quoted)[1]];
            }
            ksort($tables);

            return $tables;
        } finally {
            $db->rollBack();
        }
    }

    /** @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, mixed> $plan
     *
     * @return array<string, mixed> */
    public function compare(array $before, array $after, string $phase, array $plan = []): array
    {
        ModuleOneReserveManifest::require(in_array($phase, ['migration', 'import', 'enable', 'identical'], true), 'Phase de comparaison inconnue.');
        $targets = $plan['targets'] ?? [];
        if (in_array($phase, ['import', 'enable'], true)) {
            ModuleOneReserveManifest::require(array_keys($targets) === ['module1_test1', 'module1_test2', 'module1_test3', 'module1_test4'] && 4 === count(array_unique($targets)), 'Plan des quatre cibles requis.');
            ModuleOneReserveManifest::require([] === ($plan['errors'] ?? null) && [] === ($plan['initial_differences'] ?? null), 'Plan avec erreurs/différences.');
        }
        $expectedTables = array_keys($before);
        if ('migration' === $phase && !isset($before['quiz_reserve_import'])) {
            $expectedTables[] = 'quiz_reserve_import';
        }
        sort($expectedTables);
        ModuleOneReserveManifest::require($expectedTables === array_keys($after), 'Liste des tables différente.');
        $report = ['phase' => $phase, 'tables' => [], 'changes' => []];
        $newColumns = ['quiz' => ['renewal_enabled' => 0, 'error_question_quota' => null], 'quiz_question' => ['reserve' => 0, 'contains_error' => null]];
        foreach ($before as $table => $old) {
            $new = $after[$table];
            $left = $old['rows'];
            $right = $new['rows'];
            $oldColumns = $old['columns'];
            $columns = $new['columns'];
            $oldDdl = $old['ddl'];
            $ddl = $new['ddl'];
            if ('migration' === $phase) {
                foreach ($newColumns[$table] ?? [] as $name => $default) {
                    if (in_array($name, array_column($oldColumns, 'Field'), true)) {
                        continue;
                    }
                    $column = array_values(array_filter($columns, static fn ($c) => $c['Field'] === $name));
                    ModuleOneReserveManifest::require(1 === count($column) && $column[0]['Default'] === (null === $default ? null : (string) $default)
                        && $column[0]['Null'] === (null === $default ? 'YES' : 'NO'), 'Définition de colonne ajoutée inattendue : '.$name);
                    ModuleOneReserveManifest::require('error_question_quota' === $name ? 1 === preg_match('/^int(?:\([0-9]+\))?$/D', $column[0]['Type'])
                        : 'tinyint(1)' === $column[0]['Type'], 'Type de colonne ajoutée inattendu : '.$name);
                    foreach ($right as &$row) {
                        ModuleOneReserveManifest::require(array_key_exists($name, $row) && $row[$name] === $default, 'Défaut de migration incorrect : '.$name);
                        unset($row[$name]);
                    } unset($row);
                    $columns = array_values(array_filter($columns, static fn ($c) => $c['Field'] !== $name));
                    $ddl = preg_replace('/^  `'.preg_quote($name, '/').'`[^\n]*\n/m', '', $ddl);
                }
                if ('doctrine_migration_versions' === $table) {
                    $versions = array_column($left, 'version');
                    $allowed = ['DoctrineMigrations\\Version20261025120000', 'DoctrineMigrations\\Version20261025150000'];
                    ModuleOneReserveManifest::require([] === array_diff($allowed, array_column($right, 'version')), 'Les deux migrations doivent être présentes après déploiement.');
                    $right = array_values(array_filter($right, static fn ($r) => !in_array($r['version'], $allowed, true) || in_array($r['version'], $versions, true)));
                }
            }
            if ('import' === $phase && in_array($table, ['quiz_question', 'quiz_answer'], true)) {
                $oldDdl = preg_replace('/ AUTO_INCREMENT=\d+/', '', $oldDdl);
                $ddl = preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl);
            }
            ModuleOneReserveManifest::require($oldColumns === $columns && $oldDdl === $ddl, 'Schéma différent : '.$table);
            if ('enable' === $phase && 'quiz' === $table) {
                foreach ($left as &$row) {
                    if (in_array($row['id'], $targets, true)) {
                        ModuleOneReserveManifest::require(0 === $row['renewal_enabled'], 'Cible déjà active avant activation.');
                        $report['changes'][] = ['table' => 'quiz', 'id' => $row['id'], 'column' => 'renewal_enabled', 'before' => 0, 'after' => 1];
                        $row['renewal_enabled'] = 1;
                    }
                } unset($row);
            }
            $added = 0;
            if ('import' === $phase) {
                ModuleOneReserveManifest::require(in_array($plan['add_questions'] ?? null, [0, 94], true) && ($plan['add_answers'] ?? null) === 2 * $plan['add_questions'], 'Volume du plan invalide.');
                $added = match ($table) {
                    'quiz_question', 'quiz_reserve_import' => $plan['add_questions'], 'quiz_answer' => $plan['add_answers'], default => 0,
                };
            }
            if ($added > 0) {
                ModuleOneReserveManifest::require(count($right) === count($left) + $added, 'Nombre d’ajouts différent : '.$table);
                foreach ($left as $row) {
                    ModuleOneReserveManifest::require(in_array($row, $right, true), 'Ancienne ligne modifiée : '.$table);
                }
            } else {
                usort($left, static fn ($a, $b) => strcmp(serialize($a), serialize($b)));
                usort($right, static fn ($a, $b) => strcmp(serialize($a), serialize($b)));
                ModuleOneReserveManifest::require($left === $right, 'Données différentes : '.$table);
            }
            $report['tables'][$table] = ['rows_before' => count($old['rows']), 'rows_after' => count($new['rows']), 'existing_data_preserved' => true,
                'before_rows_sha256' => hash('sha256', serialize($old['rows'])), 'after_rows_sha256' => hash('sha256', serialize($new['rows']))];
        }
        if ('migration' === $phase && !isset($before['quiz_reserve_import'])) {
            ModuleOneReserveManifest::require([] === $after['quiz_reserve_import']['rows'], 'Table de traçabilité nouvelle non vide.');
        }
        if ('enable' === $phase) {
            ModuleOneReserveManifest::require(4 === count($report['changes']), 'Quatre activations exactes attendues.');
        }
        $report['success'] = true;

        return $report;
    }
}
