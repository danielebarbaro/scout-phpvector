<?php

declare(strict_types=1);

namespace DanieleBarbaro\ScoutPHPVector\Tests;

use DanieleBarbaro\ScoutPHPVector\Contracts\EmbeddingGenerator;
use DanieleBarbaro\ScoutPHPVector\Indexing\IndexManager;
use DanieleBarbaro\ScoutPHPVector\PHPVectorEngine;
use DanieleBarbaro\ScoutPHPVector\ScoutPHPVectorServiceProvider;
use DanieleBarbaro\ScoutPHPVector\Tests\Fixtures\Article;
use DanieleBarbaro\ScoutPHPVector\Tests\Fixtures\FakeEmbeddingGenerator;
use DanieleBarbaro\ScoutPHPVector\Tests\Fixtures\Note;
use FilesystemIterator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\EngineManager;
use Laravel\Scout\ScoutServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

abstract class TestCase extends Orchestra
{
    protected string $indexPath;

    protected FakeEmbeddingGenerator $embeddings;

    protected function setUp(): void
    {
        $this->indexPath = sys_get_temp_dir().'/scout-phpvector-tests-'.bin2hex(random_bytes(6));

        parent::setUp();

        $this->embeddings = $this->app->make(EmbeddingGenerator::class);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->indexPath);

        parent::tearDown();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ScoutServiceProvider::class,
            ScoutPHPVectorServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('scout.driver', 'phpvector');
        $app['config']->set('scout.queue', false);
        $app['config']->set('scout.prefix', '');

        $app['config']->set('scout-phpvector.path', $this->indexPath);
        // Small graphs keep the tests fast; recall is exact at this size.
        $app['config']->set('scout-phpvector.hnsw', [
            'M' => 8,
            'ef_construction' => 32,
            'ef_search' => 64,
        ]);

        $app->singleton(EmbeddingGenerator::class, static fn (): FakeEmbeddingGenerator => new FakeEmbeddingGenerator);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->text('body');
            $table->string('status')->default('published');
            $table->integer('views')->default(0);
        });

        Schema::create('notes', function (Blueprint $table): void {
            $table->increments('id');
            $table->text('body');
        });
    }

    /**
     * The engine registered on the container, sharing its loaded-index cache
     * with everything Scout does through the facade.
     */
    protected function engine(): PHPVectorEngine
    {
        $engine = $this->app->make(EngineManager::class)->engine('phpvector');

        if (! $engine instanceof PHPVectorEngine) {
            $this->fail('The phpvector driver did not resolve to a PHPVectorEngine.');
        }

        return $engine;
    }

    /**
     * A brand new engine with an empty index cache, for assertions that change
     * configuration which is baked into an already loaded index (the distance
     * metric, for instance).
     */
    protected function freshEngine(): PHPVectorEngine
    {
        return new PHPVectorEngine(
            indexes: new IndexManager(fn (): array => (array) $this->app['config']->get('scout-phpvector', [])),
            embeddings: $this->embeddings,
            softDelete: (bool) $this->app['config']->get('scout.soft_delete', false),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<Article>
     */
    public function seedArticles(array $rows): array
    {
        $articles = Article::withoutSyncingToSearch(static function () use ($rows): array {
            $created = [];

            foreach ($rows as $row) {
                $created[] = Article::create($row);
            }

            return $created;
        });

        Article::makeAllSearchable();

        return $articles;
    }

    public function seedNote(string $body): Note
    {
        $note = Note::withoutSyncingToSearch(static fn (): Note => Note::create(['body' => $body]));

        Note::makeAllSearchable();

        return $note;
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
