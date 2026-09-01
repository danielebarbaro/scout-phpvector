<?php

declare(strict_types=1);

namespace DanieleBarbaro\ScoutPHPVector\Indexing;

use Closure;
use DanieleBarbaro\ScoutPHPVector\Exceptions\LockTimeoutException;
use DanieleBarbaro\ScoutPHPVector\Exceptions\ScoutPHPVectorException;
use FilesystemIterator;
use PHPVector\BM25\Config as BM25Config;
use PHPVector\BM25\SimpleTokenizer;
use PHPVector\BM25\StopWords\EnglishStopWords;
use PHPVector\BM25\StopWords\FileStopWords;
use PHPVector\BM25\StopWords\ItalianStopWords;
use PHPVector\BM25\TokenizerInterface;
use PHPVector\Distance;
use PHPVector\Exception\LockTimeoutException as PHPVectorLockTimeoutException;
use PHPVector\HNSW\Config as HNSWConfig;
use PHPVector\Persistence\FileLock;
use PHPVector\VectorDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Opens, caches and mutates the PHPVector folders that back each Scout index.
 *
 * Reads are cached for the lifetime of the instance because loading an HNSW
 * graph is the single most expensive operation in this package. Writes always
 * discard that cache and re-read from disk under an exclusive lock, so a
 * process never saves a graph built on top of a stale snapshot.
 */
class IndexManager
{
    /** @var array<string, VectorDatabase> */
    private array $loaded = [];

    /**
     * The scout-phpvector config. A closure is resolved on every access so
     * runtime config changes (and tests) are picked up without rebuilding the
     * manager.
     *
     * @param  array<string, mixed>|Closure(): array<string, mixed>  $config
     */
    public function __construct(private readonly Closure|array $config) {}

    /**
     * Load an index for reading. Missing folders yield an empty database so
     * searching a not-yet-imported model returns no hits instead of throwing.
     */
    public function read(string $index): VectorDatabase
    {
        if (isset($this->loaded[$index])) {
            return $this->loaded[$index];
        }

        return $this->loaded[$index] = $this->lock($index)->run(
            false,
            fn (): VectorDatabase => $this->open($index),
        );
    }

    /**
     * Mutate an index under an exclusive lock and persist the result.
     *
     * @template TReturn
     *
     * @param  Closure(VectorDatabase): TReturn  $callback
     * @return TReturn
     */
    public function write(string $index, Closure $callback): mixed
    {
        return $this->lock($index)->run(true, function () use ($index, $callback): mixed {
            $this->forget($index);

            $database = $this->open($index);

            $result = $callback($database);

            try {
                $database->save();
            } catch (PHPVectorLockTimeoutException $exception) {
                throw LockTimeoutException::forIndex($index, $this->lockTimeout(), $exception);
            }

            $this->forget($index);

            return $result;
        });
    }

    /**
     * Create an empty index folder, replacing any existing one.
     */
    public function create(string $index): void
    {
        $this->lock($index)->run(true, function () use ($index): void {
            $this->forget($index);
            $this->removeDirectory($this->pathFor($index));

            $this->newDatabase($index)->save();
        });
    }

    /**
     * Remove every document from an index while keeping it usable.
     */
    public function truncate(string $index): void
    {
        $this->create($index);
    }

    /**
     * Delete an index folder entirely.
     */
    public function destroy(string $index): void
    {
        $this->lock($index)->run(true, function () use ($index): void {
            $this->forget($index);
            $this->removeDirectory($this->pathFor($index));
        });
    }

    public function exists(string $index): bool
    {
        return is_file($this->pathFor($index).'/meta.json');
    }

    public function forget(?string $index = null): void
    {
        if ($index === null) {
            $this->loaded = [];

            return;
        }

        unset($this->loaded[$index]);
    }

    public function pathFor(string $index): string
    {
        $root = rtrim((string) ($this->config()['path'] ?? ''), '/');

        if ($root === '') {
            throw new ScoutPHPVectorException('The [scout-phpvector.path] configuration value is empty.');
        }

        return $root.'/'.$this->sanitize($index);
    }

    /**
     * Resolve the effective settings for an index, merging the per-index
     * overrides declared under scout-phpvector.indexes over the defaults.
     *
     * @return array<string, mixed>
     */
    public function settingsFor(string $index): array
    {
        $defaults = $this->config();
        $overrides = $defaults['indexes'][$index] ?? [];

        unset($defaults['indexes']);

        if (! is_array($overrides)) {
            throw new ScoutPHPVectorException(sprintf(
                'The override for index [%s] under [scout-phpvector.indexes] must be an array.',
                $index,
            ));
        }

        foreach ($overrides as $key => $value) {
            $defaults[$key] = is_array($value) && is_array($defaults[$key] ?? null)
                ? array_replace($defaults[$key], $value)
                : $value;
        }

        return $defaults;
    }

    public function distanceFor(string $index): Distance
    {
        $distance = (string) ($this->settingsFor($index)['distance'] ?? 'cosine');

        return match (strtolower($distance)) {
            'cosine' => Distance::Cosine,
            'euclidean' => Distance::Euclidean,
            'dot_product', 'dotproduct' => Distance::DotProduct,
            'manhattan' => Distance::Manhattan,
            default => throw new ScoutPHPVectorException(sprintf(
                'Unknown distance [%s]. Supported: cosine, euclidean, dot_product, manhattan.',
                $distance,
            )),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return $this->config instanceof Closure ? ($this->config)() : $this->config;
    }

    private function open(string $index): VectorDatabase
    {
        $settings = $this->settingsFor($index);

        if (! $this->exists($index)) {
            return $this->newDatabase($index);
        }

        try {
            return VectorDatabase::open(
                path: $this->pathFor($index),
                hnswConfig: $this->hnswConfig($index),
                bm25Config: $this->bm25Config($settings),
                tokenizer: $this->tokenizer($settings),
                overFetchMultiplier: max(1, (int) ($settings['search']['over_fetch_multiplier'] ?? 5)),
                lockTimeout: $this->lockTimeout(),
            );
        } catch (PHPVectorLockTimeoutException $exception) {
            throw LockTimeoutException::forIndex($index, $this->lockTimeout(), $exception);
        }
    }

    private function newDatabase(string $index): VectorDatabase
    {
        $settings = $this->settingsFor($index);

        return new VectorDatabase(
            hnswConfig: $this->hnswConfig($index),
            bm25Config: $this->bm25Config($settings),
            tokenizer: $this->tokenizer($settings),
            path: $this->pathFor($index),
            overFetchMultiplier: max(1, (int) ($settings['search']['over_fetch_multiplier'] ?? 5)),
            lockTimeout: $this->lockTimeout(),
        );
    }

    private function hnswConfig(string $index): HNSWConfig
    {
        $hnsw = $this->settingsFor($index)['hnsw'] ?? [];

        return new HNSWConfig(
            M: (int) ($hnsw['M'] ?? 16),
            efConstruction: (int) ($hnsw['ef_construction'] ?? 200),
            efSearch: (int) ($hnsw['ef_search'] ?? 50),
            distance: $this->distanceFor($index),
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function bm25Config(array $settings): BM25Config
    {
        $bm25 = $settings['bm25'] ?? [];

        return new BM25Config(
            k1: (float) ($bm25['k1'] ?? 1.5),
            b: (float) ($bm25['b'] ?? 0.75),
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function tokenizer(array $settings): TokenizerInterface
    {
        $bm25 = $settings['bm25'] ?? [];
        $stopWords = $bm25['stop_words'] ?? 'english';

        $provider = match (true) {
            $stopWords === 'english' => new EnglishStopWords,
            $stopWords === 'italian' => new ItalianStopWords,
            $stopWords === 'none' => [],
            is_array($stopWords) => $stopWords,
            is_string($stopWords) => new FileStopWords($stopWords),
            default => throw new ScoutPHPVectorException(
                'The [scout-phpvector.bm25.stop_words] value must be "english", "italian", "none", '
                .'an array of words, or a path to a stop words file.',
            ),
        };

        return new SimpleTokenizer(
            stopWords: $provider,
            minTokenLength: max(1, (int) ($bm25['min_token_length'] ?? 2)),
        );
    }

    private function lockTimeout(): float
    {
        return (float) ($this->config()['lock']['timeout'] ?? FileLock::DEFAULT_TIMEOUT);
    }

    private function lock(string $index): IndexLock
    {
        return new IndexLock(
            index: $index,
            file: $this->pathFor($index).'.lock',
            timeout: $this->lockTimeout(),
        );
    }

    /**
     * Index names come from searchableAs() and may carry a Scout prefix, so
     * strip anything that could escape the configured root folder.
     */
    private function sanitize(string $index): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '_', $index) ?? '';

        if ($sanitized === '' || trim($sanitized, '.') === '') {
            throw new ScoutPHPVectorException(sprintf('The index name [%s] cannot be used as a folder name.', $index));
        }

        return $sanitized;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
