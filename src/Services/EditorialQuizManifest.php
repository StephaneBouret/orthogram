<?php

namespace App\Services;

use App\Entity\Quiz;
use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;

/** Dedicated contract; documentary fields never become entity properties. */
abstract class EditorialQuizManifest
{
    /** @var array<string, mixed> */
    public readonly array $data;
    public readonly string $hash;

    public function __construct(string $path)
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

    abstract protected function validate(): void;

    /** @return list<string> */
    abstract public function heldSourceKeys(): array;
}
