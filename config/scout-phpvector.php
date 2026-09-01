<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Index Storage Path
    |--------------------------------------------------------------------------
    |
    | Root folder that holds one sub-folder per Scout index. PHPVector persists
    | an index as a folder containing meta.json, hnsw.bin, bm25.bin and a docs/
    | directory. The folder must be writable by both the web process and the
    | queue workers that index your models.
    |
    */

    'path' => env('SCOUT_PHPVECTOR_PATH', storage_path('app/scout-phpvector')),

    /*
    |--------------------------------------------------------------------------
    | Distance Metric
    |--------------------------------------------------------------------------
    |
    | Supported: "cosine", "euclidean", "dot_product", "manhattan".
    |
    | The metric is baked into the persisted index. Changing it requires a full
    | rebuild (php artisan scout:import), PHPVector refuses to open a folder
    | that was written with a different metric.
    |
    | Only "cosine", "euclidean" and "manhattan" produce scores in [0, 1], which
    | is the range Scout's minimumSimilarity threshold assumes. "dot_product"
    | returns raw, unbounded dot products.
    |
    */

    'distance' => env('SCOUT_PHPVECTOR_DISTANCE', 'cosine'),

    /*
    |--------------------------------------------------------------------------
    | HNSW Graph Parameters
    |--------------------------------------------------------------------------
    |
    | M               Bi-directional connections per node per layer. Higher
    |                 values improve recall and cost memory and build time.
    | ef_construction Candidate list size while building. Must be >= 2 * M.
    | ef_search       Candidate list size while querying. Must be >= k.
    |
    */

    'hnsw' => [
        'M' => (int) env('SCOUT_PHPVECTOR_HNSW_M', 16),
        'ef_construction' => (int) env('SCOUT_PHPVECTOR_HNSW_EF_CONSTRUCTION', 200),
        'ef_search' => (int) env('SCOUT_PHPVECTOR_HNSW_EF_SEARCH', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | BM25 Parameters
    |--------------------------------------------------------------------------
    |
    | k1         Term frequency saturation. Typical range 1.2 to 2.0.
    | b          Length normalisation, between 0 and 1.
    | stop_words "english", "italian", "none", or an absolute path to a file
    |            containing one stop word per line.
    |
    */

    'bm25' => [
        'k1' => (float) env('SCOUT_PHPVECTOR_BM25_K1', 1.5),
        'b' => (float) env('SCOUT_PHPVECTOR_BM25_B', 0.75),
        'stop_words' => env('SCOUT_PHPVECTOR_BM25_STOP_WORDS', 'english'),
        'min_token_length' => (int) env('SCOUT_PHPVECTOR_BM25_MIN_TOKEN_LENGTH', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hybrid Fusion
    |--------------------------------------------------------------------------
    |
    | mode     "weighted" (default) forwards the weights given to Scout's
    |          hybrid($textWeight, $semanticWeight) call to PHPVector.
    |          "rrf" uses Reciprocal Rank Fusion, which ignores weights, so the
    |          engine refuses an "rrf" search whose two weights differ instead
    |          of dropping them silently.
    | rrf_k    RRF constant, only used in "rrf" mode.
    | fetch_k  Candidates pulled from each leg before fusion. null lets
    |          PHPVector pick max(k * 3, 50).
    |
    */

    'hybrid' => [
        'mode' => env('SCOUT_PHPVECTOR_HYBRID_MODE', 'weighted'),
        'rrf_k' => (int) env('SCOUT_PHPVECTOR_HYBRID_RRF_K', 60),
        'fetch_k' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Defaults
    |--------------------------------------------------------------------------
    |
    | default_limit          Results fetched when the builder has no take().
    | over_fetch_multiplier  How many extra candidates PHPVector pulls before
    |                        applying metadata filters.
    | minimum_similarity     Threshold used by semantic() when the caller does
    |                        not pass one. Matches Scout's DatabaseEngine.
    |
    */

    'search' => [
        'default_limit' => (int) env('SCOUT_PHPVECTOR_DEFAULT_LIMIT', 100),
        'over_fetch_multiplier' => (int) env('SCOUT_PHPVECTOR_OVER_FETCH', 5),
        'minimum_similarity' => (float) env('SCOUT_PHPVECTOR_MIN_SIMILARITY', 0.6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | PHPVector has no native offset. The engine fetches a window and slices it
    | in PHP.
    |
    | exact_total = false  Fetch (page * perPage) + 1 hits. Cheap, but the total
    |                      reported to LengthAwarePaginator is a lower bound.
    | exact_total = true   Fetch up to max_total_hits and count them, so the
    |                      total is exact as long as the result set is smaller
    |                      than max_total_hits. Costs a bigger k on every page.
    |
    */

    'pagination' => [
        'exact_total' => (bool) env('SCOUT_PHPVECTOR_EXACT_TOTAL', false),
        'max_total_hits' => (int) env('SCOUT_PHPVECTOR_MAX_TOTAL_HITS', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Write Lock
    |--------------------------------------------------------------------------
    |
    | PHPVector already locks and atomically rewrites each individual save()
    | and open(). On top of that, this package takes an exclusive lock on
    | {path}/{index}.lock for the whole read-modify-write cycle, so two
    | processes indexing at the same time cannot overwrite each other's
    | documents.
    |
    | The same timeout is handed to PHPVector, so it governs both locks.
    | flock() is advisory and per host: it does not protect an index shared
    | over NFS or between containers on different machines.
    |
    */

    'lock' => [
        'timeout' => (float) env('SCOUT_PHPVECTOR_LOCK_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-index Overrides
    |--------------------------------------------------------------------------
    |
    | Keyed by the value returned from the model's searchableAs(). Any of the
    | keys above ("distance", "hnsw", "bm25", "hybrid", "search", "pagination")
    | may be overridden per index.
    |
    |   'indexes' => [
    |       'articles' => [
    |           'distance' => 'euclidean',
    |           'hnsw' => ['M' => 32, 'ef_search' => 128],
    |       ],
    |   ],
    |
    */

    'indexes' => [
        //
    ],

];
