<?php

namespace App\Services;

use App\Entity\Quiz;
use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Dedicated contract; documentary fields never become entity properties. */
final class ModuleTwoQuizManifest
{
    /** @var array<string, mixed> */
    public readonly array $data;
    public readonly string $hash;

    public function __construct(#[Autowire('%kernel.project_dir%/resources/quiz/module2/module2_quiz_manifest.json')] string $path)
    {
        $bytes = file_get_contents($path);
        self::require(false !== $bytes, 'Manifeste illisible.');
        $this->hash = hash('sha256', $bytes);
        $this->data = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        $this->validate();
    }

    public static function require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \DomainException($message);
        }
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

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS));
    }

    /** @return array<string, array<string, mixed>> */
    public function sources(): array
    {
        return array_column($this->data['sources'], null, 'source_key');
    }

    /** @return list<array<string, mixed>> */
    public function copies(string $quizKey): array
    {
        $copies = array_values(array_filter($this->data['copies'], static fn ($c) => $c['quiz_key'] === $quizKey));
        usort($copies, static fn ($a, $b) => $a['position'] <=> $b['position']);

        return $copies;
    }

    /** @param array<string, mixed> $copy
     * @return array<string, mixed> */
    public function payload(array $copy): array
    {
        $candidate = $this->sources()[$copy['source_key']]['candidate'];

        return array_intersect_key($candidate, array_flip(['title', 'text', 'explanation', 'theme', 'multiple', 'contains_error', 'answers']))
            + ['position' => $copy['position'], 'reserve' => 'reserve' === $copy['role']];
    }

    /** @param array<string, mixed> $spec */
    public function build(array $spec): Quiz
    {
        $quiz = (new Quiz())->setTitle($spec['title']);
        foreach ($this->copies($spec['quiz_key']) as $copy) {
            $p = $this->payload($copy);
            $question = (new QuizQuestion())->setQuiz($quiz)->setTitle($p['title'])->setText($p['text'])->setExplanation($p['explanation'])
                ->setTheme($p['theme'])->setMultiple($p['multiple'])->setContainsError($p['contains_error'])->setPosition($p['position'])->setReserve($p['reserve']);
            foreach ($p['answers'] as $answer) {
                $question->addAnswer((new QuizAnswer())->setContent($answer['content'])->setCorrect($answer['correct'])->setPosition($answer['position']));
            }
        }

        return $quiz;
    }

    private function validate(): void
    {
        $d = $this->data;
        self::require('orthogram.quiz.editorial' === ($d['manifest_format'] ?? null) && 1 === ($d['schema_version'] ?? null) && 'module2' === ($d['module_key'] ?? null), 'Format module 2 invalide.');
        self::require(116 === count($d['sources']) && range(1, 116) === array_column($d['sources'], 'original_number'), '116 sources ordonnées requises.');
        self::require(['module2_test1', 'module2_test2', 'module2_general'] === array_column($d['quizzes'], 'quiz_key'), 'Identités des trois quiz invalides.');
        self::require([12, 12, 24] === array_column($d['quizzes'], 'expected_initial_count') && [19, 24, 86] === array_column($d['quizzes'], 'expected_reserve_count'), 'Volumes 12/12/24 et 19/24/86 requis.');
        self::require(self::hash($d['counts']) === self::hash(['sources_total' => 116, 'sources_importable' => 110, 'sources_on_hold' => 6, 'source_copies' => 177, 'initial_copies' => 48, 'reserve_copies' => 129, 'answer_rows' => 354, 'sources_with_two_copies' => 67, 'sources_with_one_copy' => 43]), 'Décomptes documentaires incohérents.');
        $sources = $this->sources();
        $holds = [];
        foreach ($d['sources'] as $s) {
            $key = $s['source_key'];
            self::require($key === sprintf('module2_docx_%03d', $s['original_number']), 'Clé source invalide : '.$key);
            self::require(self::hash($s['original']) === $s['original_sha256'], 'Original divergent : '.$key);
            if ('on_hold' === $s['status']) {
                $holds[] = $s['original_number'];
                self::require(null === $s['candidate'] && [] === $s['assignments'], 'Source en attente importable : '.$key);
                continue;
            }
            $c = $s['candidate'];
            self::require('approved_for_import' === $s['status'] && is_bool($c['contains_error']) && false === $c['multiple'] && $c['error_count'] === (int) $c['contains_error'], 'Catégorie explicite invalide : '.$key);
            self::require($c['theme'] === $s['theme'], 'Thème divergent : '.$key);
            foreach (['title', 'text', 'explanation', 'theme'] as $field) {
                self::require(is_string($c[$field]) && '' !== trim($c[$field]), $key.'/'.$field.' vide.');
            }
            self::require(self::hash($c['answers']) === self::hash([
                ['content' => '0 erreur', 'correct' => !$c['contains_error'], 'position' => 0],
                ['content' => '1 erreur', 'correct' => $c['contains_error'], 'position' => 1],
            ]), 'Propositions incohérentes : '.$key);
            $assignments = [];
            foreach ($d['copies'] as $copy) {
                if ($copy['source_key'] === $key) {
                    $assignments[] = array_intersect_key($copy, array_flip(['copy_key', 'quiz_key', 'role', 'position']));
                }
            }
            self::require(self::hash($assignments) === self::hash($s['assignments']), 'Affectations incohérentes : '.$key);
        }
        self::require([2, 44, 45, 75, 80, 111] === $holds, 'Mises en attente modifiées.');
        self::require(array_map(static fn ($n) => sprintf('module2_docx_%03d', $n), $holds) === $d['constraints']['held_source_keys'], 'Liste documentaire des attentes incohérente.');
        self::require(177 === count($d['copies']) && 177 === count(array_unique(array_column($d['copies'], 'copy_key'))), '177 copies distinctes requises.');
        foreach ($d['copies'] as $copy) {
            self::require(isset($sources[$copy['source_key']]['candidate']) && in_array($copy['quiz_key'], array_column($d['quizzes'], 'quiz_key'), true), 'Copie sans source ou quiz.');
            self::require(in_array($copy['role'], ['initial', 'reserve'], true) && is_int($copy['position']) && $copy['quiz_key'].'::'.$copy['source_key'] === $copy['copy_key'], 'Clé/rôle/position invalide.');
            self::require(self::hash($this->payload($copy)) === $copy['payload_sha256'], 'Empreinte divergente : '.$copy['copy_key']);
        }
        foreach ($d['quizzes'] as $q) {
            $key = $q['quiz_key'];
            self::require(false === $q['renewal_enabled'] && null === $q['error_question_quota'], 'Création fixe sans quota requise.');
            self::require(['policy' => 'manual_easyadmin_after_import', 'existing_course_id' => null, 'existing_quiz_id' => null, 'existing_slug' => null] === $q['course_binding'], 'Liaison manuelle requise.');
            $bank = $this->copies($key);
            self::require($q['expected_initial_count'] + $q['expected_reserve_count'] === $q['expected_total_count'] && range(0, $q['expected_total_count'] - 1) === array_column($bank, 'position'), 'Positions incohérentes : '.$key);
            foreach (['initial', 'reserve'] as $role) {
                $keys = array_column(array_filter($bank, static fn ($c) => $c['role'] === $role), 'source_key');
                self::require($keys === $q[$role.'_source_keys'] && count($keys) === $q['expected_'.$role.'_count'], 'Ordre/rôle incohérent : '.$key);
            }
            self::require(array_column($bank, 'source_key') === [...$q['initial_source_keys'], ...$q['reserve_source_keys']], 'Les initiales doivent précéder les réserves.');
            $texts = array_map(fn ($c) => $this->payload($c)['text'], $bank);
            self::require(count($texts) === count(array_unique($texts)), 'Texte dupliqué : '.$key);
            $themes = [];
            foreach ($q['themes'] as $theme) {
                $themes[] = $theme['theme'];
                foreach (['initial', 'reserve'] as $role) {
                    $keys = array_column(array_filter($bank, static fn ($c) => $c['role'] === $role && $sources[$c['source_key']]['theme'] === $theme['theme']), 'source_key');
                    self::require($keys === $theme[$role.'_source_keys'] && count($keys) === $theme['initial' === $role ? 'initial_count_and_attempt_quota' : 'reserve_count'] && [] !== $keys, 'Quota thématique incohérent : '.$key.'/'.$theme['theme']);
                }
            }
            self::require(count($themes) === count(array_unique($themes)), 'Thème dupliqué.');
            foreach ($bank as $copy) {
                self::require(in_array($sources[$copy['source_key']]['theme'], $themes, true), 'Thème orphelin.');
            }
        }
        $uses = array_count_values(array_column($d['copies'], 'source_key'));
        $multiplicities = array_count_values($uses);
        self::require(67 === ($multiplicities[2] ?? 0) && 43 === ($multiplicities[1] ?? 0) && 110 === count($uses), 'Multiplicités incohérentes.');
    }
}
