<?php

namespace App\Services;

use App\Entity\Exercice;

class ExerciceCorrectionService
{
    /**
     * @param list<string> $selectedTokenIds
     *
     * @return array{
     *     score: int,
     *     total: int,
     *     percentage: int,
     *     items: list<array{tokenId: string, status: string, explanation: string}>
     * }
     */
    public function correctClickWords(Exercice $exercice, array $selectedTokenIds): array
    {
        $selectedTokenIds = array_values(array_unique(array_filter(
            $selectedTokenIds,
            static fn (mixed $tokenId): bool => is_string($tokenId) && '' !== $tokenId
        )));

        $selected = array_fill_keys($selectedTokenIds, true);
        $tokens = $this->indexTokens($exercice);
        $expectedTokenIds = [];
        $items = [];
        $score = 0;

        foreach ($tokens as $tokenId => $token) {
            if (($token['isAnswer'] ?? false) !== true) {
                continue;
            }

            $expectedTokenIds[$tokenId] = true;

            if (isset($selected[$tokenId])) {
                ++$score;
                $items[] = $this->buildItem($tokenId, 'correct', $token);
            }
        }

        foreach ($selectedTokenIds as $tokenId) {
            if (isset($expectedTokenIds[$tokenId])) {
                continue;
            }

            $items[] = $this->buildItem($tokenId, 'wrong', $tokens[$tokenId] ?? null);
        }

        foreach ($tokens as $tokenId => $token) {
            if (($token['isAnswer'] ?? false) === true && !isset($selected[$tokenId])) {
                $items[] = $this->buildItem($tokenId, 'missed', $token);
            }
        }

        $total = count($expectedTokenIds);

        return [
            'score' => $score,
            'total' => $total,
            'percentage' => 0 === $total ? 0 : (int) round(($score / $total) * 100),
            'items' => $items,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indexTokens(Exercice $exercice): array
    {
        $tokens = [];
        $sentences = $exercice->getData()['sentences'] ?? [];

        if (!is_array($sentences)) {
            return $tokens;
        }

        foreach ($sentences as $sentence) {
            if (!is_array($sentence) || !isset($sentence['words']) || !is_array($sentence['words'])) {
                continue;
            }

            foreach ($sentence['words'] as $word) {
                if (!is_array($word) || !isset($word['id']) || !is_string($word['id'])) {
                    continue;
                }

                $tokens[$word['id']] = $word;
            }
        }

        return $tokens;
    }

    /**
     * @param array<string, mixed>|null $token
     *
     * @return array{tokenId: string, status: string, explanation: string}
     */
    private function buildItem(string $tokenId, string $status, ?array $token): array
    {
        $fallback = match ($status) {
            'wrong' => 'Ce mot n’est pas un nom attendu dans cette phrase.',
            'missed' => 'Ce mot était une réponse attendue.',
            default => 'Bonne réponse.',
        };

        return [
            'tokenId' => $tokenId,
            'status' => $status,
            'explanation' => is_string($token['explanation'] ?? null) ? $token['explanation'] : $fallback,
        ];
    }
}
