<?php

declare(strict_types=1);

namespace App\Services\Dictation;

/**
 * Alternatives apply to exactly ONE canonical unit occurrence, identified by its zero-based index.
 * An alternative contains 1..3 units. No external punctuation, overlapping passages or global rules.
 *
 * @phpstan-type VariantInput array{referenceIndex: int, alternatives: list<string>}
 *
 * @phpstan-import-type Unit from TokenizedText
 */
final class PassageVariants
{
    public function __construct(private readonly DictationTokenizer $tokenizer = new DictationTokenizer())
    {
    }

    /**
     * @param array<mixed> $variants validated at runtime, including array shapes
     *
     * @return array<int, list<list<Unit>>>
     */
    public function validate(array $variants, TokenizedText $reference): array
    {
        if (!array_is_list($variants) || count($variants) > CorrectionContract::MAX_VARIANT_PASSAGES) {
            throw new \InvalidArgumentException('Liste de variantes invalide ou trop grande.');
        }
        $result = [];
        $totalLength = 0;
        $canonicalUnits = $reference->comparisonUnits();
        foreach ($variants as $variant) {
            if (!is_array($variant) || 2 !== count($variant)
                || !isset($variant['referenceIndex'], $variant['alternatives'])
                || !is_int($variant['referenceIndex']) || !isset($reference->words[$variant['referenceIndex']])
                || isset($result[$variant['referenceIndex']]) || !is_array($variant['alternatives'])
                || !array_is_list($variant['alternatives']) || [] === $variant['alternatives']
                || count($variant['alternatives']) > CorrectionContract::MAX_ALTERNATIVES) {
                throw new \InvalidArgumentException('Passage de variante invalide.');
            }
            $alternatives = [];
            foreach ($variant['alternatives'] as $alternative) {
                if (!is_string($alternative)) {
                    throw new \InvalidArgumentException('Variante invalide.');
                }
                $text = $this->tokenizer->tokenize($alternative, 3, CorrectionContract::MAX_VARIANT_LENGTH);
                $totalLength += $text->length;
                if ([] === $text->words || [] !== $text->punctuation || $totalLength > CorrectionContract::MAX_VARIANTS_TOTAL_LENGTH) {
                    throw new \InvalidArgumentException('Contenu ou taille des variantes invalide.');
                }
                $values = $text->comparisonUnits();
                $canonical = $canonicalUnits[$variant['referenceIndex']];
                $last = count($values) - 1;
                if ($text->words[$last]['elision'] ?? false) {
                    $values[$last]['joined'] = $canonical['joined'];
                }
                if (in_array($values, $alternatives, true) || (1 === count($values)
                    && $canonical['value'] === $values[0]['value'] && TokenizedText::sameSpacing($canonical, $values[0]))) {
                    throw new \InvalidArgumentException('Variante redondante.');
                }
                $alternatives[] = $values;
            }
            $result[$variant['referenceIndex']] = $alternatives;
        }

        return $result;
    }
}
