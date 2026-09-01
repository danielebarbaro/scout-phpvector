<?php

declare(strict_types=1);

namespace DanieleBarbaro\ScoutPHPVector\Tests\Fixtures;

use DanieleBarbaro\ScoutPHPVector\Contracts\EmbeddingGenerator;

/**
 * Deterministic, offline stand-in for Laravel AI.
 *
 * Each input is projected onto a fixed vocabulary: dimension i is 1 when the
 * i-th term appears in the text. Cosine similarity between two texts is then
 * simply the overlap of their vocabularies, which makes similarity thresholds
 * predictable in tests.
 */
final class FakeEmbeddingGenerator implements EmbeddingGenerator
{
    /** @var list<string> */
    public const VOCABULARY = ['php', 'laravel', 'vector', 'database', 'cat', 'dog', 'pizza', 'pasta'];

    public int $calls = 0;

    /**
     * @param  list<string>  $inputs
     * @return list<list<float>>
     */
    public function generate(array $inputs): array
    {
        $this->calls++;

        return array_map(static function (string $input): array {
            $haystack = mb_strtolower($input);

            $vector = array_map(
                static fn (string $term): float => str_contains($haystack, $term) ? 1.0 : 0.0,
                self::VOCABULARY,
            );

            // A zero vector has no direction; nudge the last dimension so the
            // cosine distance stays defined for texts outside the vocabulary.
            if (array_sum($vector) === 0.0) {
                $vector[count($vector) - 1] = 0.0001;
            }

            return $vector;
        }, array_values($inputs));
    }
}
