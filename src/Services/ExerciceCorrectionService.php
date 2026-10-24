<?php

namespace App\Services;

use App\Entity\Exercice;

class ExerciceCorrectionService
{
    /** @param array{score: int, total: int, items: list<array{status: string, tokenId: string, sentenceId: ?string, explanation: string}>, percentage: int} $result */
    public function isSuccessful(Exercice $exercice, array $result): bool
    {
        if ($result['total'] <= 0 || $result['score'] !== $result['total']) {
            return false;
        }
        foreach ($result['items'] as $item) {
            if ('correct' !== $item['status']) {
                return false;
            }
        }

        // Keep the correction scale (including virtual noAnswer points), but do
        // not complete an empty or unusable exercise on virtual points alone.
        $sentences = $exercice->getSentences();
        if ([] === $sentences) {
            return false;
        }
        $ids = [];
        foreach ($sentences as $sentence) {
            if (!is_string($sentence['id'] ?? null) || '' === $sentence['id']
                || !is_array($sentence['words'] ?? null) || [] === $sentence['words']) {
                return false;
            }
            $hasAnswer = ($sentence['noAnswer'] ?? false) === true;
            foreach ($sentence['words'] as $word) {
                if (!is_array($word) || !is_string($word['id'] ?? null) || '' === $word['id']
                    || isset($ids[$word['id']]) || !is_string($word['text'] ?? null) || '' === trim($word['text'])) {
                    return false;
                }
                $ids[$word['id']] = true;
                $hasAnswer = $hasAnswer || ($word['isAnswer'] ?? false) === true;
            }
            if (!$hasAnswer) {
                return false;
            }
            if (($sentence['noAnswer'] ?? false) === true) {
                $virtualId = $sentence['id'].'__none';
                if (isset($ids[$virtualId])) {
                    return false;
                }
                $ids[$virtualId] = true;
            }
        }

        return true;
    }

    /**
     * @param array<int|string, mixed> $selectedTokenIds
     *
     * @return array{
     *     score: int,
     *     total: int,
     *     percentage: int,
     *     items: list<array{tokenId: string, sentenceId: string|null, status: string, explanation: string}>
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
        foreach ($tokens as $tokenId => $token) {
            if (!isset($token['noAnswerWordIds'])) {
                continue;
            }

            // A virtual answer is derived only from the real words in its sentence.
            unset($selected[$tokenId]);
            if (!array_intersect($token['noAnswerWordIds'], $selectedTokenIds)) {
                $selected[$tokenId] = true;
            }
        }

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

            --$score;
            $items[] = $this->buildItem($tokenId, 'wrong', $tokens[$tokenId] ?? null);
        }

        foreach ($tokens as $tokenId => $token) {
            if (($token['isAnswer'] ?? false) === true && !isset($selected[$tokenId])) {
                $items[] = $this->buildItem($tokenId, 'missed', $token);
            }
        }

        $score = max(0, $score);
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

            $noAnswer = ($sentence['noAnswer'] ?? false) === true;
            $wordIds = [];

            foreach ($sentence['words'] as $word) {
                if (!is_array($word) || !isset($word['id']) || !is_string($word['id'])) {
                    continue;
                }

                if ($noAnswer) {
                    $word['isAnswer'] = false;
                }

                $word['sentenceId'] = $sentence['id'];
                $tokens[$word['id']] = $word;
                $wordIds[] = $word['id'];
            }

            if ($noAnswer) {
                $tokens[$sentence['id'].'__none'] = [
                    'sentenceId' => $sentence['id'],
                    'isAnswer' => true,
                    'noAnswerWordIds' => $wordIds,
                    'explanation' => $sentence['noAnswerExplanation'] ?? 'Aucune réponse attendue dans cette phrase.',
                ];
            }
        }

        return $tokens;
    }

    /**
     * @param array<string, mixed>|null $token
     *
     * @return array{tokenId: string, sentenceId: string|null, status: string, explanation: string}
     */
    private function buildItem(string $tokenId, string $status, ?array $token): array
    {
        $fallback = match ($status) {
            'wrong' => 'Cette sélection n’est pas une réponse attendue dans cette phrase.',
            'missed' => 'Ce mot était une réponse attendue.',
            default => 'Bonne réponse.',
        };

        return [
            'tokenId' => $tokenId,
            'sentenceId' => $token['sentenceId'] ?? null,
            'status' => $status,
            'explanation' => is_string($token['explanation'] ?? null) ? $token['explanation'] : $fallback,
        ];
    }
}
