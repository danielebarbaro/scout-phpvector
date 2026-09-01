<?php

declare(strict_types=1);

use DanieleBarbaro\ScoutPHPVector\PHPVectorEngine;
use DanieleBarbaro\ScoutPHPVector\Tests\Fixtures\Article;
use Laravel\Scout\Contracts\SupportsSemanticSearch;
use Laravel\Scout\EngineManager;

it('registers the phpvector driver as a semantic capable engine', function (): void {
    $engine = $this->app->make(EngineManager::class)->engine('phpvector');

    expect($engine)
        ->toBeInstanceOf(PHPVectorEngine::class)
        ->toBeInstanceOf(SupportsSemanticSearch::class);
});

it('ranks models with bm25', function (): void {
    $this->seedArticles([
        ['title' => 'Laravel routing', 'body' => 'Laravel is a php framework'],
        ['title' => 'Feeding a cat', 'body' => 'A cat eats twice a day'],
        ['title' => 'Baking pizza', 'body' => 'Pizza needs a very hot oven'],
    ]);

    $results = Article::search('laravel')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->title)->toBe('Laravel routing');
});

it('returns an empty collection when nothing matches', function (): void {
    $this->seedArticles([
        ['title' => 'Feeding a cat', 'body' => 'A cat eats twice a day'],
    ]);

    expect(Article::search('kubernetes')->get())->toHaveCount(0);
});

it('returns no hits for an index that was never imported', function (): void {
    expect(Article::search('laravel')->get())->toHaveCount(0);
});

it('honours the take limit', function (): void {
    $this->seedArticles([
        ['title' => 'Vector one', 'body' => 'vector database in php'],
        ['title' => 'Vector two', 'body' => 'vector database in php'],
        ['title' => 'Vector three', 'body' => 'vector database in php'],
    ]);

    expect(Article::search('vector')->take(2)->get())->toHaveCount(2);
});

it('removes deleted models from the index', function (): void {
    $articles = $this->seedArticles([
        ['title' => 'Laravel routing', 'body' => 'Laravel is a php framework'],
        ['title' => 'Laravel queues', 'body' => 'Laravel ships a queue system'],
    ]);

    expect(Article::search('laravel')->get())->toHaveCount(2);

    $articles[0]->delete();

    expect(Article::search('laravel')->get())->toHaveCount(1);
});

it('falls back to a metadata only search for a blank query', function (): void {
    $this->seedArticles([
        ['title' => 'Draft note', 'body' => 'php draft', 'status' => 'draft'],
        ['title' => 'Live note', 'body' => 'php live', 'status' => 'published'],
    ]);

    $results = Article::search('')->where('status', 'published')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->title)->toBe('Live note');
});

it('flushes an index', function (): void {
    $this->seedArticles([
        ['title' => 'Laravel routing', 'body' => 'Laravel is a php framework'],
    ]);

    expect(Article::search('laravel')->get())->toHaveCount(1);

    Article::removeAllFromSearch();

    expect(Article::search('laravel')->get())->toHaveCount(0);
});
