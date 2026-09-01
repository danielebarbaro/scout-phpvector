<?php

declare(strict_types=1);

namespace DanieleBarbaro\ScoutPHPVector;

use DanieleBarbaro\ScoutPHPVector\Contracts\EmbeddingGenerator;
use DanieleBarbaro\ScoutPHPVector\Exceptions\ScoutPHPVectorException;
use DanieleBarbaro\ScoutPHPVector\Indexing\DocumentMapper;
use DanieleBarbaro\ScoutPHPVector\Indexing\IndexManager;
use DanieleBarbaro\ScoutPHPVector\Search\FilterTranslator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\SupportsSemanticSearch;
use Laravel\Scout\Engines\Engine;
use PHPVector\Distance;
use PHPVector\Document;
use PHPVector\HybridMode;
use PHPVector\Metadata\MetadataFilter;
use PHPVector\SearchResult;
use PHPVector\VectorDatabase;

/**
 * Laravel Scout engine backed by PHPVector.
 *
 * Routing, driven entirely by the state Scout puts on the builder:
 *
 *   $builder->semanticSearch === true  -> VectorDatabase::vectorSearch()
 *   $builder->hybridSearch !== null    -> VectorDatabase::hybridSearch()
 *   blank query, only filters          -> VectorDatabase::metadataSearch()
 *   otherwise                          -> VectorDatabase::textSearch() (BM25)
 *
 * The raw result handed back to Scout is:
 *
 *   ['hits' => list<array{id, score, rank, metadata}>, 'total' => int, 'exact_total' => bool]
 *
 * Note on signatures: the abstract methods inherited from Scout's Engine
 * declare untyped parameters. PHP forbids narrowing an inherited parameter
 * type, so those parameters stay untyped here and are documented instead.
 * Return types and every method this class owns are fully typed.
 */
class PHPVectorEngine extends Engine implements SupportsSemanticSearch
{
    private readonly FilterTranslator $filters;

    private readonly DocumentMapper $mapper;

    public function __construct(
        private readonly IndexManager $indexes,
        private readonly EmbeddingGenerator $embeddings,
        private readonly bool $softDelete = false,
    ) {
        $this->filters = new FilterTranslator;
        $this->mapper = new DocumentMapper($this->embeddings, $this->softDelete);
    }

    /**
     * Add or replace the given models in the index.
     *
     * @param  EloquentCollection<int, Model>  $models
     */
    public function update($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $documents = $this->mapper->map($models);

        if ($documents === []) {
            return;
        }

        $this->indexes->write(
            $this->writeIndexOf($models->first()),
            function (VectorDatabase $database) use ($documents): void {
                foreach ($documents as $document) {
                    if (! $database->updateDocument($document)) {
                        $database->addDocument($document);
                    }
                }
            },
        );
    }

    /**
     * Remove the given models from the index.
     *
     * @param  EloquentCollection<int, Model>  $models
     */
    public function delete($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $index = $this->writeIndexOf($models->first());

        if (! $this->indexes->exists($index)) {
            return;
        }

        $keys = [];

        foreach ($models as $model) {
            $keys[] = $this->mapper->keyFor($model);
        }

        $this->indexes->write($index, function (VectorDatabase $database) use ($keys): void {
            foreach ($keys as $key) {
                $database->deleteDocument($key);
            }
        });
    }

    /**
     * @param  Builder<Model>  $builder
     * @return array{hits: list<array<string, mixed>>, total: int, exact_total: bool}
     */
    public function search(Builder $builder): array
    {
        $hits = $this->performSearch($builder, $this->limitFor($builder));

        return [
            'hits' => $hits,
            'total' => count($hits),
            'exact_total' => true,
        ];
    }

    /**
     * PHPVector has no native offset: a window of (page * perPage) hits is
     * fetched and sliced in PHP. See the README for what that costs.
     *
     * @param  Builder<Model>  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return array{hits: list<array<string, mixed>>, total: int, exact_total: bool}
     */
    public function paginate(Builder $builder, $perPage, $page): array
    {
        $perPage = max(1, (int) $perPage);
        $page = max(1, (int) $page);

        $pagination = $this->settingsFor($builder)['pagination'] ?? [];
        $exactTotal = (bool) ($pagination['exact_total'] ?? false);

        $window = $perPage * $page;

        $fetch = $exactTotal
            ? max($window, (int) ($pagination['max_total_hits'] ?? 1000))
            : $window + 1;

        $hits = $this->performSearch($builder, $fetch);
        $found = count($hits);

        return [
            'hits' => array_slice($hits, ($page - 1) * $perPage, $perPage),
            'total' => $found,
            // The total is exact whenever the whole result set fitted in the
            // window; otherwise it is a lower bound.
            'exact_total' => $found < $fetch,
        ];
    }

    /**
     * @param  array{hits: list<array<string, mixed>>}|null  $results
     * @return Collection<int, mixed>
     */
    public function mapIds($results): Collection
    {
        return collect($this->hitsOf($results))->pluck('id')->values();
    }

    /**
     * @param  Builder<Model>  $builder
     * @param  array{hits: list<array<string, mixed>>}|null  $results
     * @param  Model  $model
     * @return EloquentCollection<int, Model>
     */
    public function map(Builder $builder, $results, $model): EloquentCollection
    {
        $hits = $this->hitsOf($results);

        if ($hits === []) {
            return $model->newCollection();
        }

        $keys = array_column($hits, 'id');
        $positions = array_flip($keys);
        $hitsByKey = array_combine($keys, $hits);

        return $model->getScoutModelsByIds($builder, $keys)
            ->filter(fn (Model $model): bool => isset($positions[$model->getScoutKey()]))
            ->map(fn (Model $model): Model => $this->withScoutMetadata($model, $hitsByKey[$model->getScoutKey()]))
            ->sortBy(fn (Model $model): int => $positions[$model->getScoutKey()])
            ->values();
    }

    /**
     * @param  Builder<Model>  $builder
     * @param  array{hits: list<array<string, mixed>>}|null  $results
     * @param  Model  $model
     * @return LazyCollection<int, Model>
     */
    public function lazyMap(Builder $builder, $results, $model): LazyCollection
    {
        $hits = $this->hitsOf($results);

        if ($hits === []) {
            return LazyCollection::make($model->newCollection());
        }

        $keys = array_column($hits, 'id');
        $positions = array_flip($keys);
        $hitsByKey = array_combine($keys, $hits);

        return $model->queryScoutModelsByIds($builder, $keys)
            ->cursor()
            ->filter(fn (Model $model): bool => isset($positions[$model->getScoutKey()]))
            ->map(fn (Model $model): Model => $this->withScoutMetadata($model, $hitsByKey[$model->getScoutKey()]))
            ->sortBy(fn (Model $model): int => $positions[$model->getScoutKey()])
            ->values();
    }

    /**
     * @param  array{total?: int, hits?: list<array<string, mixed>>}|null  $results
     */
    public function getTotalCount($results): int
    {
        if (is_array($results) && isset($results['total'])) {
            return (int) $results['total'];
        }

        return count($this->hitsOf($results));
    }

    /**
     * @param  Model  $model
     */
    public function flush($model): void
    {
        $this->indexes->truncate($this->writeIndexOf($model));
    }

    /**
     * @param  string  $name
     * @param  array<string, mixed>  $options
     */
    public function createIndex($name, array $options = []): bool
    {
        $this->indexes->create($name);

        return true;
    }

    /**
     * @param  string  $name
     */
    public function deleteIndex($name): bool
    {
        $this->indexes->destroy($name);

        return true;
    }

    /**
     * Run the query against PHPVector and return normalised hits.
     *
     * @param  Builder<Model>  $builder
     * @return list<array<string, mixed>>
     */
    protected function performSearch(Builder $builder, int $k): array
    {
        $k = max(1, $k);
        $index = $this->indexOf($builder->model, $builder->index);
        $filters = $this->filters->translate($builder);
        $database = $this->indexes->read($index);

        if ($builder->callback !== null) {
            /** @var list<SearchResult> $results */
            $results = call_user_func($builder->callback, $database, $builder->query, [
                'index' => $index,
                'k' => $k,
                'filters' => $filters,
            ]);

            return $this->toHits($results);
        }

        $results = match (true) {
            $builder->semanticSearch === true => $this->semanticSearch($builder, $database, $k, $filters),
            $builder->hybridSearch !== null => $this->hybridSearch($builder, $database, $k, $filters),
            trim((string) $builder->query) === '' => $database->metadataSearch(filters: $filters, limit: $k),
            default => $database->textSearch(query: (string) $builder->query, k: $k, filters: $filters),
        };

        return $this->applyOrders($builder, $this->toHits($results));
    }

    /**
     * Pure vector search, with Scout's minimum similarity applied as a score
     * cut-off. PHPVector returns results sorted by score descending, so
     * trimming the tail is exact and needs no over-fetch.
     *
     * @param  Builder<Model>  $builder
     * @param  list<MetadataFilter>  $filters
     * @return list<SearchResult>
     */
    protected function semanticSearch(Builder $builder, VectorDatabase $database, int $k, array $filters): array
    {
        $threshold = $this->minimumSimilarity($builder);

        $results = $database->vectorSearch(
            vector: $this->queryVector($builder),
            k: $k,
            filters: $filters,
        );

        return array_values(array_filter(
            $results,
            static fn (SearchResult $result): bool => $result->score >= $threshold,
        ));
    }

    /**
     * @param  Builder<Model>  $builder
     * @param  list<MetadataFilter>  $filters
     * @return list<SearchResult>
     */
    protected function hybridSearch(Builder $builder, VectorDatabase $database, int $k, array $filters): array
    {
        if ($builder->minimumSimilarity !== null) {
            throw new ScoutPHPVectorException(
                'PHPVector fuses the vector and BM25 legs of a hybrid search internally and exposes no per-leg '
                .'similarity threshold, so hybrid($textWeight, $semanticWeight, $minSimilarity) cannot honour the '
                .'third argument. Drop it, or use semantic($minSimilarity) when the threshold matters.',
            );
        }

        $settings = $this->settingsFor($builder);
        $hybrid = $settings['hybrid'] ?? [];

        $textWeight = (float) ($builder->hybridSearch['text_weight'] ?? 1);
        $semanticWeight = (float) ($builder->hybridSearch['semantic_weight'] ?? 1);

        $mode = match (strtolower((string) ($hybrid['mode'] ?? 'weighted'))) {
            'weighted' => HybridMode::Weighted,
            'rrf' => HybridMode::RRF,
            default => throw new ScoutPHPVectorException(sprintf(
                'Unknown hybrid fusion mode [%s]. Supported: weighted, rrf.',
                (string) ($hybrid['mode'] ?? ''),
            )),
        };

        if ($mode === HybridMode::RRF && $textWeight !== $semanticWeight) {
            throw new ScoutPHPVectorException(
                'Reciprocal Rank Fusion is rank based and ignores weights, but hybrid() was called with '
                .'text_weight and semantic_weight that differ. Set [scout-phpvector.hybrid.mode] to "weighted" '
                .'to have the weights applied.',
            );
        }

        $fetchK = $hybrid['fetch_k'] ?? null;

        return array_values($database->hybridSearch(
            vector: $this->queryVector($builder),
            text: (string) $builder->query,
            k: $k,
            fetchK: $fetchK === null ? null : (int) $fetchK,
            mode: $mode,
            vectorWeight: $semanticWeight,
            textWeight: $textWeight,
            rrfK: (int) ($hybrid['rrf_k'] ?? 60),
            filters: $filters,
        ));
    }

    /**
     * @param  Builder<Model>  $builder
     * @return list<float>
     */
    protected function queryVector(Builder $builder): array
    {
        if (! method_exists($builder->model, 'toSearchableEmbedding')) {
            throw new ScoutPHPVectorException(sprintf(
                'Semantic and hybrid searches require [%s] to define a [toSearchableEmbedding] method.',
                $builder->model::class,
            ));
        }

        $vectors = $this->embeddings->generate([(string) $builder->query]);

        if (! isset($vectors[0]) || $vectors[0] === []) {
            throw new ScoutPHPVectorException('The embedding generator returned no vector for the search query.');
        }

        return array_map('floatval', $vectors[0]);
    }

    /**
     * Scout's contract: the threshold defaults to 0.6 and must sit in [0, 1].
     *
     * @param  Builder<Model>  $builder
     */
    protected function minimumSimilarity(Builder $builder): float
    {
        $settings = $this->settingsFor($builder);
        $similarity = $builder->minimumSimilarity ?? ($settings['search']['minimum_similarity'] ?? 0.6);

        if (! is_numeric($similarity) || $similarity < 0 || $similarity > 1) {
            throw new ScoutPHPVectorException('The minimum similarity must be between 0 and 1.');
        }

        $similarity = (float) $similarity;

        $index = $this->indexOf($builder->model, $builder->index);

        // A raw dot product is unbounded, so cutting it off at a number in
        // [0, 1] filters on something meaningless. Refuse rather than return
        // a silently wrong result set; 0 disables the cut-off and is allowed.
        if ($similarity > 0.0 && $this->indexes->distanceFor($index) === Distance::DotProduct) {
            throw new ScoutPHPVectorException(
                'The [dot_product] distance produces raw, unbounded scores, so a minimum similarity in [0, 1] is '
                .'meaningless against them. Configure [scout-phpvector.distance] as cosine, euclidean or manhattan '
                .'for this index, or set the threshold to 0 to disable the cut-off.',
            );
        }

        return $similarity;
    }

    /**
     * Scout order clauses are applied to the fetched window only, because
     * PHPVector ranks by relevance and cannot sort the whole index.
     *
     * @param  Builder<Model>  $builder
     * @param  list<array<string, mixed>>  $hits
     * @return list<array<string, mixed>>
     */
    protected function applyOrders(Builder $builder, array $hits): array
    {
        if ($builder->orders === []) {
            return $hits;
        }

        $orders = $builder->orders;

        usort($hits, static function (array $a, array $b) use ($orders): int {
            foreach ($orders as $order) {
                $column = (string) ($order['column'] ?? '');
                $direction = strtolower((string) ($order['direction'] ?? 'asc'));

                $left = $a['metadata'][$column] ?? null;
                $right = $b['metadata'][$column] ?? null;

                $comparison = $left <=> $right;

                if ($comparison !== 0) {
                    return $direction === 'desc' ? -$comparison : $comparison;
                }
            }

            return 0;
        });

        return $hits;
    }

    /**
     * @param  iterable<int, mixed>  $results
     * @return list<array<string, mixed>>
     */
    protected function toHits(iterable $results): array
    {
        $hits = [];
        $rank = 0;

        foreach ($results as $result) {
            if (! $result instanceof SearchResult) {
                throw new ScoutPHPVectorException(sprintf(
                    'Expected a list of [%s], got [%s].',
                    SearchResult::class,
                    get_debug_type($result),
                ));
            }

            /** @var Document $document */
            $document = $result->document;

            $hits[] = [
                'id' => $document->id,
                'score' => $result->score,
                'rank' => ++$rank,
                'metadata' => $document->metadata,
            ];
        }

        return $hits;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function hitsOf(mixed $results): array
    {
        if (! is_array($results) || ! isset($results['hits']) || ! is_array($results['hits'])) {
            return [];
        }

        return array_values($results['hits']);
    }

    /**
     * @param  array<string, mixed>  $hit
     */
    protected function withScoutMetadata(Model $model, array $hit): Model
    {
        if (method_exists($model, 'withScoutMetadata')) {
            $model->withScoutMetadata('_score', $hit['score'] ?? null);
            $model->withScoutMetadata('_rank', $hit['rank'] ?? null);
        }

        return $model;
    }

    /**
     * @param  Builder<Model>  $builder
     */
    protected function limitFor(Builder $builder): int
    {
        $default = $this->settingsFor($builder)['search']['default_limit'] ?? 100;

        return max(1, (int) ($builder->limit ?? $default));
    }

    /**
     * @param  Builder<Model>|string  $subject
     * @return array<string, mixed>
     */
    protected function settingsFor(Builder|string $subject): array
    {
        $index = $subject instanceof Builder
            ? $this->indexOf($subject->model, $subject->index)
            : $subject;

        return $this->indexes->settingsFor($index);
    }

    /**
     * Reads use searchableAs(); an explicit within() on the builder wins.
     */
    protected function indexOf(Model $model, ?string $override = null): string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }

        return (string) $model->searchableAs();
    }

    /**
     * Writes use indexableAs(), matching the rest of Scout, so blue/green
     * re-imports land in the staging index.
     */
    protected function writeIndexOf(Model $model): string
    {
        return method_exists($model, 'indexableAs')
            ? (string) $model->indexableAs()
            : (string) $model->searchableAs();
    }

    /**
     * Expose the underlying database, mostly for tinkering and diagnostics.
     */
    public function database(string $index): VectorDatabase
    {
        return $this->indexes->read($index);
    }

    public function indexes(): IndexManager
    {
        return $this->indexes;
    }
}
