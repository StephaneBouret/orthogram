<?php

namespace App\Services;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Filesystem\Path;

/** Editorial v1 for error-count quizzes. Only payload() fields reach Doctrine. */
#[Exclude]
final class LocalQuizManifest extends EditorialQuizManifest
{
    public function __construct(string $path, string $publicDirectory)
    {
        self::require('' !== trim($path) && !str_contains($path, "\0") && !str_contains($path, '://')
            && !str_starts_with(str_replace('\\', '/', $path), '//'), 'Fichier JSON local requis.');
        $file = realpath($path);
        $public = realpath($publicDirectory);
        self::require(false !== $file && is_file($file) && is_readable($file) && 'json' === strtolower(pathinfo($file, PATHINFO_EXTENSION)), 'Fichier JSON local illisible.');
        self::require(false !== $public && !Path::isBasePath(
            '\\' === DIRECTORY_SEPARATOR ? strtolower($public) : $public,
            '\\' === DIRECTORY_SEPARATOR ? strtolower($file) : $file
        ), 'Le manifeste doit être hors de la racine publique.');
        try {
            parent::__construct($file);
        } catch (\JsonException $error) {
            throw new \DomainException('JSON invalide : '.$error->getMessage(), previous: $error);
        }
    }

    public function heldSourceKeys(): array
    {
        return array_column(array_filter($this->data['sources'], static fn ($s) => 'on_hold' === $s['status']), 'source_key');
    }

    protected function validate(): void
    {
        $data = $this->data;
        $module = $data['module_key'] ?? null;
        self::require(is_string($module) && 1 === preg_match('/^[a-z][a-z0-9_-]*$/D', $module)
            && in_array($data['format'] ?? null, ['orthogram.editorial.v1', 'orthogram.editorial.'.$module.'.v1'], true), 'Format éditorial v1 ou clé de module invalide.');
        foreach (['quizzes', 'copies', 'sources'] as $field) {
            self::require(is_array($data[$field] ?? null) && array_is_list($data[$field]) && [] !== $data[$field], 'Liste vide ou invalide : '.$field);
        }
        self::require(is_array($data['policy'] ?? null) && self::hash($data['policy']) === self::hash([
            'import_status_allowlist' => ['retained'], 'automatic_course_binding' => false, 'new_quizzes_fixed' => true,
            'error_question_quota' => null, 'corrected_text_is_documentary' => true, 'activation' => 'separate', 'new_questions' => 0,
        ]), 'Politique éditoriale v1 incohérente.');
        foreach (['quizzes' => 'quiz_key', 'sources' => 'source_key'] as $list => $field) {
            $seen = [];
            foreach ($data[$list] as $row) {
                $key = $row[$field] ?? null;
                self::require(is_string($key) && '' !== $key && !isset($seen[$key]), 'Clé absente ou dupliquée : '.$field);
                $seen[$key] = true;
            }
        }
        $quizKeys = array_column($data['quizzes'], 'quiz_key');
        $sources = $this->sources();
        $assignments = [];
        foreach ($data['copies'] as $copy) {
            $source = $copy['source_key'] ?? '';
            $quiz = $copy['quiz_key'] ?? '';
            self::require(is_string($source) && isset($sources[$source]) && 'retained' === ($sources[$source]['status'] ?? null)
                && is_string($quiz) && in_array($quiz, $quizKeys, true), 'Source exclue ou référence de copie inconnue.');
            self::require(($copy['copy_key'] ?? null) === $quiz.'::'.$source && !isset($assignments[$source][$quiz])
                && in_array($copy['role'] ?? null, ['initial', 'reserve'], true) && is_int($copy['position'] ?? null), 'Copie ou affectation invalide : '.$source);
            $assignments[$source][$quiz] = array_intersect_key($copy, array_flip(['copy_key', 'quiz_key', 'role', 'position']));
        }
        $texts = [];
        foreach ($sources as $key => $source) {
            self::require(is_int($source['original_number'] ?? null) && $source['original_number'] > 0
                && $key === sprintf('%s_docx_%03d', $module, $source['original_number']) && strlen($key) <= 96, 'Clé source invalide : '.$key);
            self::require(is_array($source['original'] ?? null) && self::hash($source['original']) === ($source['original_sha256'] ?? null), 'Original divergent : '.$key);
            $assigned = array_values($assignments[$key] ?? []);
            self::require(is_array($source['assignments'] ?? null) && self::hash($assigned) === self::hash($source['assignments']), 'Affectations divergentes : '.$key);
            if ('on_hold' === ($source['status'] ?? null)) {
                self::require([] === $assigned && array_key_exists('candidate', $source) && null === $source['candidate'], 'Source en attente affectée : '.$key);
                continue;
            }
            self::require('retained' === ($source['status'] ?? null) && [] !== $assigned && is_array($source['candidate'] ?? null), 'Source retenue invalide : '.$key);
            $c = $source['candidate'];
            foreach (['title', 'text', 'theme', 'explanation', 'corrected_text'] as $field) {
                self::require(is_string($c[$field] ?? null) && '' !== trim($c[$field]), 'Champ vide : '.$key.'/'.$field);
            }
            self::require(!isset($texts[$c['text']]) && $c['theme'] === ($source['theme'] ?? null), 'Texte dupliqué ou thème divergent : '.$key);
            $texts[$c['text']] = true;
            self::require(false === ($c['multiple'] ?? null) && is_bool($c['contains_error'] ?? null)
                && ($c['error_count'] ?? null) === (int) $c['contains_error'], 'Catégorie de réponse invalide : '.$key);
            $answers = [
                ['content' => '0 erreur', 'correct' => !$c['contains_error'], 'position' => 0],
                ['content' => '1 erreur', 'correct' => $c['contains_error'], 'position' => 1],
            ];
            self::require(is_array($c['answers'] ?? null) && self::hash($c['answers']) === self::hash($answers), 'Propositions incohérentes : '.$key);
        }
        foreach ($data['copies'] as $copy) {
            self::require(self::hash($this->payload($copy)) === ($copy['payload_sha256'] ?? null), 'Empreinte de copie divergente : '.$copy['copy_key']);
        }
        $titles = [];
        foreach ($data['quizzes'] as $quiz) {
            $key = $quiz['quiz_key'];
            self::require(is_string($key) && strlen($key) <= 96 && 1 === preg_match('/^'.$module.'_[a-z0-9][a-z0-9_-]*$/D', $key), 'Clé de quiz invalide.');
            self::require(is_string($quiz['title'] ?? null) && '' !== trim($quiz['title']), 'Titre absent : '.$key);
            $title = mb_strtolower(trim($quiz['title']));
            self::require(!isset($titles[$title]), 'Titre de quiz dupliqué : '.$key);
            $titles[$title] = true;
            $this->validateBank($quiz, $sources);
        }
        $roles = array_count_values(array_column($data['copies'], 'role'));
        $uses = array_count_values(array_map('count', $assignments));
        self::require(is_array($data['counts'] ?? null) && self::hash($data['counts']) === self::hash([
            'sources_total' => count($sources), 'sources_retained' => count($assignments), 'sources_on_hold' => count($this->heldSourceKeys()),
            'copies' => count($data['copies']), 'initial_copies' => $roles['initial'] ?? 0, 'reserve_copies' => $roles['reserve'] ?? 0,
            'answer_rows' => 2 * count($data['copies']), 'sources_with_one_copy' => $uses[1] ?? 0, 'sources_with_two_copies' => $uses[2] ?? 0,
        ]), 'Décomptes du manifeste incohérents.');
    }

    /** @param array<string, mixed> $quiz
     * @param array<string, array<string, mixed>> $sources */
    private function validateBank(array $quiz, array $sources): void
    {
        $key = $quiz['quiz_key'];
        self::require(false === ($quiz['renewal_enabled'] ?? null) && array_key_exists('error_question_quota', $quiz) && null === $quiz['error_question_quota']
            && 'manual_after_import' === ($quiz['course_binding'] ?? null), 'Création fixe sans quota, rattachement manuel requis : '.$key);
        $bank = $this->copies($key);
        $initial = [];
        $themes = [];
        foreach ($bank as $position => $copy) {
            self::require($position === $copy['position'], 'Position manquante ou dupliquée : '.$key);
            $source = $sources[$copy['source_key']];
            $themes[$source['theme']][$copy['role']][] = $source['original_number'];
            if ('initial' === $copy['role']) {
                self::require(count($initial) === $position, 'Initiale après les réserves : '.$key);
                $initial[] = $copy['source_key'];
            }
        }
        self::require([] !== $initial && $initial === ($quiz['initial_source_keys'] ?? null)
            && count($initial) === ($quiz['expected_initial_count'] ?? null) && count($bank) - count($initial) === ($quiz['expected_reserve_count'] ?? null), 'Ordre ou effectifs divergents : '.$key);
        self::require(is_array($quiz['theme_matrix'] ?? null) && array_is_list($quiz['theme_matrix']), 'Matrice thématique requise : '.$key);
        foreach ($quiz['theme_matrix'] as $row) {
            $theme = $row['theme'] ?? '';
            self::require(is_string($theme) && '' !== $theme, 'Thème invalide : '.$key);
            $slots = $themes[$theme]['initial'] ?? [];
            $reserves = $themes[$theme]['reserve'] ?? [];
            self::require([] !== $slots && $slots === ($row['initial'] ?? null) && $reserves === ($row['reserve'] ?? null)
                && count($slots) === ($row['per_attempt'] ?? null) && max(0, count($slots) - count($reserves)) === ($row['min_repeat_vs_previous'] ?? null), 'Matrice ou quota divergent : '.$key.'/'.$theme);
            unset($themes[$theme]);
        }
        self::require([] === $themes, 'Thème absent de la matrice : '.$key);
    }
}
