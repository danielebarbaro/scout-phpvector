<?php

declare(strict_types=1);

namespace DanieleBarbaro\ScoutPHPVector\Indexing;

use DanieleBarbaro\ScoutPHPVector\Contracts\EmbeddingGenerator;
use DanieleBarbaro\ScoutPHPVector\Exceptions\ScoutPHPVectorException;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use JsonSerializable;
use Laravel\Scout\Exceptions\ScoutException;
use PHPVector\Document;

/**
 * Turns Eloquent models into PHPVector documents.
 *
 * Each document carries three things:
 *   - the BM25 text, from toSearchableText() when the model defines it,
 *     otherwise every string-ish value of toSearchableArray();
 *   - the metadata, which is the searchable array itself, so Scout's where()
 *     constraints can be evaluated against it;
 *   - the vector, from toSearchableEmbedding(), generated in batches of 100.
 */
final class DocumentMapper
{
    private const int EMBEDDING_BATCH_SIZE = 100;

    public function __construct(
        private readonly EmbeddingGenerator $embeddings,
        private readonly bool $softDelete = false,
    ) {}

    /**
     * @param  iterable<int, Model>  $models
     * @return list<Document>
     */
    public function map(iterable $models): array
    {
        $records = [];

        foreach ($models as $model) {
            $searchable = $model->toSearchableArray();

            if (empty($searchable)) {
                continue;
            }

            if (method_exists($model, 'scoutMetadata')) {
                $searchable = array_merge($searchable, $model->scoutMetadata());
            }

            if ($this->softDelete && $this->usesSoftDelete($model)) {
                $searchable['__soft_deleted'] = (int) $model->trashed();
            }

            $records[] = [
                'model' => $model,
                'metadata' => $this->normalizeMetadata($searchable),
            ];
        }

        if ($records === []) {
            return [];
        }

        $vectors = $this->vectorsFor($records);

        $documents = [];

        foreach ($records as $position => $record) {
            /** @var Model $model */
            $model = $record['model'];

            $documents[] = new Document(
                id: $this->keyFor($model),
                vector: $vectors[$position] ?? [],
                text: $this->textFor($model, $record['metadata']),
                metadata: $record['metadata'],
            );
        }

        return $documents;
    }

    /**
     * PHPVector document ids must be string|int, Scout keys can be anything
     * castable to one.
     */
    public function keyFor(Model $model): string|int
    {
        $key = $model->getScoutKey();

        if (is_int($key) || is_string($key)) {
            return $key;
        }

        if (is_scalar($key)) {
            return (string) $key;
        }

        throw new ScoutPHPVectorException(sprintf(
            'The Scout key of [%s] must be an int or a string, [%s] given.',
            $model::class,
            get_debug_type($key),
        ));
    }

    /**
     * @param  list<array{model: Model, metadata: array<string, mixed>}>  $records
     * @return array<int, list<float>>
     */
    private function vectorsFor(array $records): array
    {
        $vectors = [];

        foreach (array_chunk($records, self::EMBEDDING_BATCH_SIZE, true) as $batch) {
            $inputs = [];

            foreach ($batch as $position => $record) {
                /** @var Model $model */
                $model = $record['model'];

                if (! method_exists($model, 'toSearchableEmbedding')) {
                    continue;
                }

                $input = $model->toSearchableEmbedding();

                if (is_array($input)) {
                    $vectors[$position] = array_values(array_map('floatval', $input));

                    continue;
                }

                if (! is_string($input) || trim($input) === '') {
                    throw new ScoutException(
                        'The [toSearchableEmbedding] method must return a non-empty string or an embedding array.',
                    );
                }

                $inputs[$position] = $input;
            }

            if ($inputs === []) {
                continue;
            }

            $generated = $this->embeddings->generate(array_values($inputs));

            foreach (array_keys($inputs) as $offset => $position) {
                $vectors[$position] = array_map('floatval', $generated[$offset]);
            }
        }

        return $vectors;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function textFor(Model $model, array $metadata): string
    {
        if (method_exists($model, 'toSearchableText')) {
            $text = $model->toSearchableText();

            if (! is_string($text)) {
                throw new ScoutPHPVectorException(sprintf(
                    'The [toSearchableText] method of [%s] must return a string.',
                    $model::class,
                ));
            }

            return $text;
        }

        $ignored = [$model->getScoutKeyName(), '__soft_deleted'];

        if (method_exists($model, 'searchableEmbeddingColumn')) {
            $ignored[] = (string) $model->searchableEmbeddingColumn();
        }

        $parts = [];

        foreach ($metadata as $key => $value) {
            if (in_array($key, $ignored, true)) {
                continue;
            }

            if (is_string($value) && trim($value) !== '') {
                $parts[] = $value;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * PHPVector serialises metadata verbatim and compares it with ===, so only
     * plain scalars and arrays are stored.
     *
     * @param  array<array-key, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeMetadata(array $attributes): array
    {
        $normalized = [];

        foreach ($attributes as $key => $value) {
            $normalized[(string) $key] = $this->normalizeValue($value);
        }

        return $normalized;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            return $this->normalizeMetadata($value);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof Arrayable) {
            return $this->normalizeMetadata($value->toArray());
        }

        if ($value instanceof JsonSerializable) {
            $serialized = $value->jsonSerialize();

            return is_array($serialized) ? $this->normalizeMetadata($serialized) : $this->normalizeValue($serialized);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        throw new ScoutPHPVectorException(sprintf(
            'Searchable values must be scalars, arrays, dates or stringable objects, [%s] given.',
            get_debug_type($value),
        ));
    }

    private function usesSoftDelete(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }
}
