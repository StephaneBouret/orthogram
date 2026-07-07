<?php

namespace App\Entity;

use App\Repository\ExerciceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ExerciceRepository::class)]
class Exercice
{
    public const TYPE_CLICK_WORDS = 'click_words';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le titre de l’exercice est obligatoire.')]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'La consigne de l’exercice est obligatoire.')]
    private ?string $instruction = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Le type d’exercice est obligatoire.')]
    private string $type = self::TYPE_CLICK_WORDS;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column]
    private array $data = [];

    /**
     * @var Collection<int, Courses>
     */
    #[ORM\OneToMany(targetEntity: Courses::class, mappedBy: 'exercice')]
    private Collection $courses;

    public function __construct()
    {
        $this->courses = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->title ?? 'Exercice sans titre';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getInstruction(): ?string
    {
        return $this->instruction;
    }

    public function setInstruction(string $instruction): static
    {
        $this->instruction = $instruction;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setData(array $data): static
    {
        $this->data = $this->normalizeData($data);

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSentences(): array
    {
        $sentences = $this->data['sentences'] ?? [];

        return is_array($sentences) ? array_values($sentences) : [];
    }

    /**
     * @param list<array<string, mixed>> $sentences
     */
    public function setSentences(array $sentences): static
    {
        $this->data['sentences'] = $sentences;
        $this->data = $this->normalizeData($this->data);

        return $this;
    }

    public function getDataAsJson(): string
    {
        return json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function setDataAsJson(?string $dataAsJson): static
    {
        $dataAsJson = trim((string) $dataAsJson);

        if ('' === $dataAsJson) {
            $this->data = [];

            return $this;
        }

        $decoded = json_decode($dataAsJson, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Les données JSON de l’exercice doivent être un objet.');
        }

        $this->data = $this->normalizeData($decoded);

        return $this;
    }

    /**
     * @return Collection<int, Courses>
     */
    public function getCourses(): Collection
    {
        return $this->courses;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalizeData(array $data): array
    {
        $sentences = $data['sentences'] ?? [];

        if (!is_array($sentences)) {
            $data['sentences'] = [];

            return $data;
        }

        $normalizedSentences = [];

        foreach (array_values($sentences) as $sentenceIndex => $sentence) {
            if (!is_array($sentence)) {
                continue;
            }

            $sentenceId = $this->normalizeIdentifier($sentence['id'] ?? null, sprintf('s%d', $sentenceIndex + 1));
            $words = $sentence['words'] ?? [];
            $normalizedWords = [];

            if (is_array($words)) {
                foreach (array_values($words) as $wordIndex => $word) {
                    if (!is_array($word)) {
                        continue;
                    }

                    $text = trim((string) ($word['text'] ?? ''));

                    if ('' === $text) {
                        continue;
                    }

                    $normalizedWord = [
                        'id' => $this->normalizeIdentifier($word['id'] ?? null, sprintf('%s_w%d', $sentenceId, $wordIndex + 1)),
                        'text' => $text,
                        'isAnswer' => filter_var($word['isAnswer'] ?? false, FILTER_VALIDATE_BOOL),
                    ];

                    $punctuationAfter = (string) ($word['punctuationAfter'] ?? $word['after'] ?? '');
                    if ('' !== trim($punctuationAfter)) {
                        $normalizedWord['punctuationAfter'] = $this->normalizePunctuationAfter($punctuationAfter);
                    }

                    $explanation = trim((string) ($word['explanation'] ?? ''));
                    if ('' !== $explanation) {
                        $normalizedWord['explanation'] = $explanation;
                    }

                    $normalizedWords[] = $normalizedWord;
                }
            }

            $normalizedSentences[] = [
                'id' => $sentenceId,
                'words' => $normalizedWords,
            ];
        }

        $data['sentences'] = $normalizedSentences;

        return $data;
    }

    private function normalizeIdentifier(mixed $value, string $fallback): string
    {
        $value = is_string($value) ? trim($value) : '';

        return '' !== $value ? $value : $fallback;
    }

    private function normalizePunctuationAfter(string $punctuationAfter): string
    {
        $trimmed = trim($punctuationAfter);

        if (in_array($trimmed, [':', ';', '?', '!'], true)) {
            return ' '.$trimmed;
        }

        return $punctuationAfter;
    }
}
