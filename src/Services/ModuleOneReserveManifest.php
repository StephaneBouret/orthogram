<?php

namespace App\Services;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ModuleOneReserveManifest
{
    /** @var array<string, mixed> */
    public readonly array $data;
    public readonly string $hash;

    public function __construct(#[Autowire('%kernel.project_dir%/resources/quiz/module1_reserve.json')] string $path)
    {
        $json = file_get_contents($path);
        if (false === $json) {
            throw new \DomainException('Manifeste introuvable.');
        }
        $this->data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->hash = hash('sha256', $json);
        $this->check();
    }

    /** @return array<string, array<string, mixed>> */
    public function sources(): array
    {
        return array_column($this->data['sources'], null, 'source_key');
    }

    private function check(): void
    {
        $d = $this->data;
        self::require(1 === ($d['schema_version'] ?? null), 'Version du manifeste inconnue.');
        self::require(47 === count($d['sources']) && 47 === count($this->sources()), '47 sources distinctes requises.');
        self::require([14, 12, 12, 20] === array_column($d['quizzes'], 'expected_initial_count'), 'Jeux initiaux inattendus.');
        self::require([16, 13, 18, 47] === array_map(static fn ($q) => count($q['source_keys']), $d['quizzes']), 'Répartition inattendue.');
        self::require([10, 12, 12, 22] === array_column($d['fundamentals'], 'expected_initial_count'), 'Fondamentaux inattendus.');
        $copies = [];
        foreach ($d['sources'] as $source) {
            $c = $source['candidate'];
            self::require(1 === preg_match('/^module1_docx_[0-9]{3}$/D', $source['source_key']), 'Clé source invalide.');
            self::require(is_bool($c['contains_error']) && true === $c['reserve'] && false === $c['multiple'], 'Métadonnées explicites requises.');
            self::require($c['answers'] === [
                ['content' => '0 erreur', 'correct' => !$c['contains_error'], 'position' => 0],
                ['content' => '1 erreur', 'correct' => $c['contains_error'], 'position' => 1],
            ], 'Propositions incohérentes avec la catégorie explicite.');
            foreach (['title', 'text', 'explanation', 'corrected_text'] as $field) {
                self::require(is_string($c[$field]) && '' !== trim($c[$field]), 'Champ documentaire manquant : '.$field);
            }
        }
        foreach ($d['quizzes'] as $quiz) {
            self::require(count($quiz['initial_questions']) === $quiz['expected_initial_count'], 'Référence initiale incomplète.');
            self::require(count(array_unique($quiz['source_keys'])) === count($quiz['source_keys']), 'Source dupliquée dans un quiz.');
            $initialThemes = $reserveThemes = $texts = [];
            foreach ($quiz['initial_questions'] as $initial) {
                $theme = $initial['expected_existing_payload']['theme'];
                $initialThemes[$theme] = ($initialThemes[$theme] ?? 0) + 1;
            }
            foreach ($quiz['source_keys'] as $key) {
                self::require(isset($this->sources()[$key]), 'Source inconnue : '.$key);
                $source = $this->sources()[$key];
                self::require(isset($initialThemes[$source['theme']]), 'Thème sans initiale : '.$source['theme']);
                self::require(!isset($texts[$source['candidate']['text']]), 'Collision de textes dans le même quiz.');
                $texts[$source['candidate']['text']] = true;
                $reserveThemes[$source['theme']] = ($reserveThemes[$source['theme']] ?? 0) + 1;
                $copies[$key][] = $quiz['quiz_key'];
            }
            foreach ($quiz['themes'] as $theme) {
                self::require(($initialThemes[$theme['theme']] ?? 0) === $theme['initial_count_and_attempt_quota']
                    && ($reserveThemes[$theme['theme']] ?? 0) === $theme['reserve_count'], 'Comptes thématiques contradictoires.');
            }
        }
        self::require(47 === count($copies), 'Source non affectée.');
        foreach ($copies as $keys) {
            self::require(2 === count($keys) && in_array('module1_test4', $keys, true), 'Une copie intermédiaire et une copie générale requises.');
        }
    }

    public static function require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \DomainException($message);
        }
    }
}
