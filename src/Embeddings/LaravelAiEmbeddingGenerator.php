<?php

declare(strict_types=1);

namespace DanieleBarbaro\ScoutPHPVector\Embeddings;

use DanieleBarbaro\ScoutPHPVector\Contracts\EmbeddingGenerator;
use Laravel\Scout\Exceptions\ScoutException;

/**
 * Generates embeddings through the optional Laravel AI SDK.
 *
 * This package deliberately owns no provider client and no HTTP layer: it
 * delegates to Laravel\Ai\Embeddings exactly the way Scout's own
 * DatabaseEngine, MeilisearchEngine and TurbopufferEngine do, including the
 * cache() call and the class_exists() guard.
 */
final class LaravelAiEmbeddingGenerator implements EmbeddingGenerator
{
    /**
     * Fully qualified name of the Laravel AI entry point, referenced as a
     * string so this package never hard-depends on the SDK.
     */
    public const string EMBEDDINGS_CLASS = 'Laravel\\Ai\\Embeddings';

    public static function isAvailable(): bool
    {
        return class_exists(self::EMBEDDINGS_CLASS);
    }

    /**
     * @param  list<string>  $inputs
     * @return list<list<float>>
     */
    public function generate(array $inputs): array
    {
        if ($inputs === []) {
            return [];
        }

        $embeddingsClass = self::EMBEDDINGS_CLASS;

        // Inlined rather than routed through isAvailable() so that static
        // analysis narrows $embeddingsClass to a class-string here.
        if (! class_exists($embeddingsClass)) {
            throw new ScoutException(
                'Semantic search requires the Laravel AI SDK. Please install the [laravel/ai] package.',
            );
        }

        $embeddings = $embeddingsClass::for($inputs)
            ->cache()
            ->generate()
            ->embeddings;

        if (! is_array($embeddings) || count($embeddings) !== count($inputs)) {
            throw new ScoutException('Laravel AI returned an unexpected number of embeddings.');
        }

        return array_values($embeddings);
    }
}
