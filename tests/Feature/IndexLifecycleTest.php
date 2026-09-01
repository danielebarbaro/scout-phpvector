<?php

declare(strict_types=1);

use DanieleBarbaro\ScoutPHPVector\Exceptions\LockTimeoutException;
use DanieleBarbaro\ScoutPHPVector\Tests\Fixtures\Article;
use Illuminate\Support\LazyCollection;
use PHPVector\Persistence\FileLock;

it('creates and deletes an index folder', function (): void {
    $engine = $this->engine();

    expect($engine->createIndex('articles'))->toBeTrue()
        ->and($this->indexPath.'/articles/meta.json')->toBeFile();

    expect($engine->deleteIndex('articles'))->toBeTrue()
        ->and($this->indexPath.'/articles')->not->toBeDirectory();
});

it('maps ids in relevance order', function (): void {
    $articles = $this->seedArticles([
        ['title' => 'Vector one', 'body' => 'vector vector vector database'],
        ['title' => 'Vector two', 'body' => 'vector database'],
    ]);

    $ids = $this->engine()->mapIds($this->engine()->search(Article::search('vector')));

    expect($ids->all())->toBe([$articles[0]->id, $articles[1]->id]);
});

it('lazy maps results onto models', function (): void {
    $this->seedArticles([
        ['title' => 'Vector one', 'body' => 'vector database php'],
    ]);

    $builder = Article::search('vector');
    $lazy = $this->engine()->lazyMap($builder, $this->engine()->search($builder), new Article);

    expect($lazy)->toBeInstanceOf(LazyCollection::class)
        ->and($lazy->pluck('title')->all())->toBe(['Vector one']);
});

it('reports the total count of a raw result', function (): void {
    $this->seedArticles([
        ['title' => 'Vector one', 'body' => 'vector database php'],
        ['title' => 'Vector two', 'body' => 'vector database php'],
    ]);

    expect($this->engine()->getTotalCount($this->engine()->search(Article::search('vector'))))->toBe(2)
        ->and($this->engine()->getTotalCount(null))->toBe(0);
});

it('does not overwrite the documents of the first write with a second one', function (): void {
    // write() re-reads the folder from disk inside the exclusive lock, so
    // batch two is added on top of batch one rather than replacing it.
    $this->seedArticles([['title' => 'First', 'body' => 'vector database php']]);
    $this->seedArticles([['title' => 'Second', 'body' => 'vector database php']]);

    expect(Article::search('vector')->get())->toHaveCount(2);
});

it('reindexes an updated model in place', function (): void {
    $articles = $this->seedArticles([['title' => 'Original', 'body' => 'vector database php']]);

    $articles[0]->update(['body' => 'pizza pasta recipes']);

    expect(Article::search('vector')->get())->toHaveCount(0)
        ->and(Article::search('pizza')->get())->toHaveCount(1)
        ->and($this->engine()->database('articles')->count())->toBe(1);
});

it('fails fast when another process holds the index lock', function (): void {
    $this->seedArticles([['title' => 'Vector one', 'body' => 'vector database php']]);

    config()->set('scout-phpvector.lock.timeout', 0.1);

    // A separate open file description on the same lock file behaves like
    // a competing process as far as flock() is concerned.
    $competitor = new FileLock($this->indexPath.'/articles.lock');
    $competitor->acquireExclusive(1.0);

    try {
        expect(fn (): array => $this->freshEngine()->search(Article::search('vector')))
            ->toThrow(LockTimeoutException::class, 'WithoutOverlapping');
    } finally {
        $competitor->release();
    }
});

it('refuses an index name that would escape the root folder', function (): void {
    $engine = $this->engine();

    $engine->createIndex('../escaped');

    expect(dirname($this->indexPath).'/escaped')->not->toBeDirectory()
        ->and($this->indexPath.'/.._escaped/meta.json')->toBeFile();
});
