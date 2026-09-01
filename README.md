# Scout PHPVector

A [Laravel Scout](https://laravel.com/docs/scout) engine backed by
[ezimuel/phpvector](https://github.com/ezimuel/PHPVector), a vector database
written in pure PHP (HNSW for approximate nearest neighbour search, BM25 for
full text ranking).

No Algolia, no Meilisearch, no Typesense, no Docker container. The index is a
folder on your own disk, and everything runs inside your PHP process.

It implements Scout 11.6's `SupportsSemanticSearch` contract, so
`->semantic()` and `->hybrid()` work exactly as they do on the first party
drivers.

## Requirements

| | |
|---|---|
| PHP | 8.3+ |
| Laravel | 12.x or 13.x |
| Laravel Scout | 11.6+ |
| `laravel/ai` | optional, required only for semantic and hybrid search |

## Installation

```bash
composer require danielebarbaro/scout-phpvector
php artisan vendor:publish --tag=scout-phpvector-config
```

Point Scout at the driver:

```dotenv
SCOUT_DRIVER=phpvector
SCOUT_PHPVECTOR_PATH="${APP_STORAGE_PATH}/app/scout-phpvector"
```

### Embeddings

This package never talks to an embedding provider. It has no HTTP client and no
provider SDK. Like Scout's own `DatabaseEngine`, `MeilisearchEngine` and
`TurbopufferEngine`, it delegates to the Laravel AI SDK:

```php
Laravel\Ai\Embeddings::for($inputs)->cache()->generate()->embeddings;
```

So semantic and hybrid search need that package:

```bash
composer require laravel/ai
```

Without it, any `->semantic()` or `->hybrid()` call throws a `ScoutException`
carrying the message *"Semantic search requires the Laravel AI SDK. Please
install the [laravel/ai] package."* Full text search keeps working, because
BM25 needs no embeddings at all.

## Preparing a model

```php
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Article extends Model
{
    use Searchable;

    public function searchableAs(): string
    {
        return 'articles';
    }

    /**
     * Stored as PHPVector document metadata. Every key here is filterable
     * with Scout's where(), whereIn() and whereNotIn().
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status,
            'views' => (int) $this->views,
        ];
    }

    /**
     * Text sent to the embedding model. Return a string to have Laravel AI
     * embed it, or return a float array to supply your own vector.
     */
    public function toSearchableEmbedding(): string
    {
        return $this->title.' '.$this->body;
    }

    /**
     * Optional. Without it, the BM25 document text is the concatenation of
     * every string value in toSearchableArray().
     */
    public function toSearchableText(): string
    {
        return $this->title.' '.$this->body;
    }
}
```

Then build the index:

```bash
php artisan scout:import "App\Models\Article"
```

## Usage

### Full text (BM25)

```php
Article::search('laravel queues')->get();
Article::search('laravel queues')->take(20)->get();
Article::search('laravel queues')->where('status', 'published')->get();
```

### Semantic (HNSW vector search)

```php
// Default minimum similarity of 0.6, matching Scout's DatabaseEngine.
Article::search('how do I run background jobs')->semantic()->get();

// Explicit threshold. Must be in [0, 1].
Article::search('how do I run background jobs')
    ->semantic(minSimilarity: 0.75)
    ->get();

// Filters and limits compose with it.
Article::search('how do I run background jobs')
    ->semantic(0.7)
    ->where('status', 'published')
    ->whereNotIn('category', ['archive'])
    ->take(10)
    ->get();
```

The threshold is applied to PHPVector's similarity score. With the default
`cosine` distance, that score is exactly the cosine similarity of the query and
document vectors.

### Hybrid (BM25 fused with HNSW)

```php
// Equal weighting.
Article::search('background jobs')->hybrid()->get();

// Twice as much weight on the semantic leg as on the keyword leg.
Article::search('background jobs')
    ->hybrid(textWeight: 1, semanticWeight: 2)
    ->get();
```

Weights are forwarded to `PHPVector\VectorDatabase::hybridSearch()` using
`HybridMode::Weighted`, which min max normalises each leg and combines them
linearly.

`hybrid()` accepts a third `minSimilarity` argument, and this engine **rejects
it with an exception**. PHPVector fuses both legs internally and exposes no per
leg threshold, so honouring that argument is impossible. Silently ignoring it
would return rows the caller explicitly asked to exclude, so it throws instead.
Use `semantic($minSimilarity)` when the threshold matters.

### Reading the score

```php
$article = Article::search('background jobs')->semantic()->first();

$article->scoutMetadata()['_score']; // float
$article->scoutMetadata()['_rank'];  // 1-based position
```

### Raw access

```php
use Laravel\Scout\EngineManager;

$db = app(EngineManager::class)->engine('phpvector')->database('articles');
$db->count();
```

You can also take over the query entirely through Scout's callback, which
receives the `VectorDatabase` and must return a `PHPVector\SearchResult[]`:

```php
Article::search('anything', function (VectorDatabase $db, string $query, array $options) {
    return $db->metadataSearch(
        filters: [MetadataFilter::gte('views', 1000)],
        limit: $options['k'],
        sortBy: 'views',
        sortDirection: SortDirection::Desc,
    );
})->get();
```

## How Scout maps onto PHPVector

| Scout | PHPVector |
|---|---|
| `Model::search('x')->get()` | `VectorDatabase::textSearch()` (BM25) |
| `->semantic()` / `->semantic(0.8)` | `VectorDatabase::vectorSearch()`, then a score cut off |
| `->hybrid(1, 2)` | `VectorDatabase::hybridSearch()` with `HybridMode::Weighted` |
| `search('')->where(...)` | `VectorDatabase::metadataSearch()` |
| `->where('f', 'v')`, `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=` | `MetadataFilter` with the matching operator |
| `->whereIn('f', [...])` | `MetadataFilter::in()` |
| `->whereNotIn('f', [...])` | `MetadataFilter::notIn()` |
| `->take(n)` | the `k` argument of every search method |
| `->orderBy('col')` | local sort of the fetched window (see limits) |
| `->paginate()` | fetch a window, slice in PHP (see limits) |
| `engine->update($models)` | `addDocument()` / `updateDocument()` under a write lock |
| `engine->delete($models)` | `deleteDocument()` under a write lock |
| `engine->flush($model)` | folder wiped and recreated empty |
| `engine->createIndex($n)` | empty folder created and saved |
| `engine->deleteIndex($n)` | folder removed |
| `toSearchableArray()` | `Document::$metadata` |
| `toSearchableText()`, or the string values of `toSearchableArray()` | `Document::$text` (BM25) |
| `toSearchableEmbedding()` | `Document::$vector` |
| `getScoutKey()` | `Document::$id` |

### Where operators that do not translate

Scout's `where()` accepts any operator string. Operators without a
`MetadataFilter` equivalent (`like`, `not like`, `between` and anything else)
raise `UnsupportedOperatorException` rather than being dropped. A search that
quietly ignores a constraint returns wrong rows, which is worse than a failure.

### Metadata comparison is strict

PHPVector compares metadata with `===`. `where('views', '50')` does **not**
match the integer `50`. Cast your values in `toSearchableArray()` and pass the
same type to `where()`.

## Configuration

`config/scout-phpvector.php`, abridged:

```php
'path'     => storage_path('app/scout-phpvector'),
'distance' => 'cosine',              // cosine | euclidean | dot_product | manhattan

'hnsw' => ['M' => 16, 'ef_construction' => 200, 'ef_search' => 50],
'bm25' => ['k1' => 1.5, 'b' => 0.75, 'stop_words' => 'english', 'min_token_length' => 2],

'hybrid' => ['mode' => 'weighted', 'rrf_k' => 60, 'fetch_k' => null],

'search' => [
    'default_limit'         => 100,
    'over_fetch_multiplier' => 5,
    'minimum_similarity'    => 0.6,
],

'pagination' => ['exact_total' => false, 'max_total_hits' => 1000],
'lock'       => ['timeout' => 10],

'indexes' => [
    'articles' => ['hnsw' => ['M' => 32, 'ef_search' => 128]],
],
```

Anything under the top level keys can be overridden per index in `indexes`,
keyed by the value returned from `searchableAs()`.

Two notes:

* `distance` is baked into the persisted folder. Changing it requires a full
  `scout:import`, PHPVector refuses to open a folder written with a different
  metric.
* Setting `hybrid.mode` to `rrf` makes fusion rank based, which ignores
  weights. Calling `hybrid()` with two different weights in that mode throws,
  instead of pretending the weights were applied.

## Concurrency and writes

This is the part that bites people, so it gets its own section.

**The index is a folder of files.** PHPVector locks and atomically rewrites
each individual `save()` and `open()`, which means you never get a half written
`hnsw.bin`. That is not sufficient on its own: Scout's `update()` and
`delete()` are read modify write cycles (open the folder, mutate the graph in
memory, save it back). Two processes doing that concurrently would each save a
graph built on their own snapshot, and the second save would drop the first
one's documents.

So this package takes an **exclusive `flock()` on `{path}/{index}.lock` around
the whole cycle**, re-reads the folder from disk inside the lock, mutates, saves
and releases. Reads take a shared lock while loading. A contended lock throws
`LockTimeoutException` after `lock.timeout` seconds rather than hanging.

`flock()` is advisory and host local. **It does not protect an index on NFS, or
shared between containers running on different machines.** If your index lives
on a network filesystem, this package cannot keep it consistent.

### What you should actually do in production

Push indexing onto a dedicated queue:

```dotenv
SCOUT_QUEUE=true
```

```php
// config/scout.php
'queue' => [
    'connection' => env('SCOUT_QUEUE_CONNECTION', 'redis'),
    'queue' => 'scout',
],
```

Scout's own jobs carry no middleware, so wrap them to add
`WithoutOverlapping`:

```php
// app/Jobs/MakeSearchableWithoutOverlapping.php
namespace App\Jobs;

use Illuminate\Queue\Middleware\WithoutOverlapping;
use Laravel\Scout\Jobs\MakeSearchable;

class MakeSearchableWithoutOverlapping extends MakeSearchable
{
    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('scout-phpvector'))->releaseAfter(5)];
    }
}
```

and register it, together with the matching subclass of
`Laravel\Scout\Jobs\RemoveFromSearch`:

```php
// app/Providers/AppServiceProvider.php
use App\Jobs\MakeSearchableWithoutOverlapping;
use App\Jobs\RemoveFromSearchWithoutOverlapping;
use Laravel\Scout\Scout;

public function boot(): void
{
    Scout::makeSearchableUsing(MakeSearchableWithoutOverlapping::class);
    Scout::removeFromSearchUsing(RemoveFromSearchWithoutOverlapping::class);
}
```

Then run a **single** worker for that queue:

```bash
php artisan queue:work --queue=scout
```

`WithoutOverlapping` needs a cache lock and only coordinates jobs, so the
single worker is what actually guarantees serialization. The `flock()` in this
package is the safety net for everything that bypasses the queue: `scout:import`,
`tinker`, an artisan command, a second worker started by mistake.

## Limits

Read this before choosing the package. None of it is theoretical.

### The whole HNSW graph is loaded into memory on every request

`VectorDatabase::open()` reads `meta.json`, `hnsw.bin` and `bm25.bin` into RAM.
Only individual documents are lazy loaded. From PHPVector's own benchmarks,
**100k vectors at 128 dimensions is roughly 500 MB**.

Under php-fpm that cost is paid on every request that touches the index, and it
lands inside `memory_limit`. The realistic range is **about 10k to 50k
documents**. Past that you need a persistent process that loads the graph once
(Laravel Octane, a long lived worker, or a dedicated search process), or a
different backend.

Real embedding models make this worse, not better: 1536 dimensions is twelve
times the memory of the 128 dimension benchmark for the same document count.

### `scout:import` rebuilds the entire index

There is no incremental bulk build. Every import inserts every document into a
fresh graph, and HNSW construction is not cheap: each insertion runs a beam
search at `ef_construction` width across every layer. Budget for it, and do not
run it inside a web request.

Re-indexing an existing document goes through delete plus insert. The HNSW node
is soft deleted and stays in the graph for connectivity, so repeated updates
grow the file over time. A periodic `scout:import` is the compaction story.

### No native offset in pagination

PHPVector's search methods take a `k` and return the top `k`. There is no
`offset`. `paginate()` therefore fetches a window of `page * perPage` hits and
slices it in PHP. Two consequences:

* Deep pages cost more than shallow ones. Page 50 at 20 per page fetches 1000
  hits to show 20.
* The `total` handed to `LengthAwarePaginator` is, by default, a lower bound.
  The engine fetches `(page * perPage) + 1` and reports what it found, so
  "there is at least one more page" is accurate but the total is not. Set
  `pagination.exact_total` to `true` to fetch up to `max_total_hits` and count
  them, at the cost of a much larger `k` on every page. The raw result exposes
  an `exact_total` boolean so you can tell which one you got.

`simplePaginate()` is the honest choice when you do not need a count.

### `orderBy()` sorts the fetched window, not the index

PHPVector ranks by relevance and cannot sort the whole collection. Scout order
clauses are applied to the hits that came back, so `orderBy('views', 'desc')`
gives you the highest `views` **among the top `k` most relevant rows**, not the
highest `views` overall. If you need true ordering, sort in the database using
Scout's `query()` callback.

### Other sharp edges

* Metadata is compared with `===`. Type mismatches silently return nothing.
* `dot_product` distance produces unbounded scores, so a `[0, 1]` similarity
  threshold is meaningless against it. The engine throws on any non zero
  threshold for such an index, including the configured default, rather than
  filtering on a number that means nothing. Set the threshold to `0` to
  disable the cut-off and use `dot_product` anyway.
* Approximate nearest neighbour search is approximate. Low `ef_search` values
  trade recall for latency.
* Soft delete support follows `scout.soft_delete`, storing `__soft_deleted` in
  the metadata.

## Local development against a PHPVector checkout

`composer.json` ships a `path` repository pointing at `../PHPVector`, so with
the two repositories side by side the dependency is symlinked into `vendor/`:

```
Github/
  PHPVector/
  scout-phpvector/
```

The `options.versions` entry pins that local checkout to the version this
package requires. Remove the whole `repositories` block to install the released
package from Packagist instead. If `../PHPVector` does not exist, Composer
ignores the repository and falls back to Packagist on its own.

## Testing

```bash
composer install
composer test
```

`composer test` chains PHPStan (`composer analyse`), Pint in check mode
(`composer lint:check`), the Pest type coverage gate (`composer test:types`)
and the Pest suite (`composer test:unit`). Each step can be run on its own.

The suite is written in Pest and runs on `orchestra/testbench` with an in memory
SQLite database and a deterministic offline embedding generator, so no API key
and no network access are needed. `laravel/ai` is deliberately absent from `require-dev` so the
"SDK is missing" path is exercised for real.

## License

MIT. See [LICENSE.md](LICENSE.md).
