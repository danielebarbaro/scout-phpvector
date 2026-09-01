<?php

declare(strict_types=1);

use DanieleBarbaro\ScoutPHPVector\Embeddings\LaravelAiEmbeddingGenerator;
use DanieleBarbaro\ScoutPHPVector\Indexing\IndexManager;
use DanieleBarbaro\ScoutPHPVector\PHPVectorEngine;
use DanieleBarbaro\ScoutPHPVector\Tests\Fixtures\Article;
use Laravel\Scout\Exceptions\ScoutException;

/*
 * laravel/ai is deliberately not a dependency of this package, so these tests
 * run against the real "SDK is missing" branch.
 */

it('confirms the laravel ai sdk is not installed in this suite', function (): void {
    expect(LaravelAiEmbeddingGenerator::isAvailable())->toBeFalse(
        'laravel/ai must stay out of require/require-dev for this test to be meaningful.',
    );
});

it('explains how to install the sdk when it is missing', function (): void {
    expect(fn (): array => (new LaravelAiEmbeddingGenerator)->generate(['a query']))->toThrow(
        ScoutException::class,
        'Semantic search requires the Laravel AI SDK. Please install the [laravel/ai] package.',
    );
});

it('short circuits on an empty batch without touching the sdk', function (): void {
    expect((new LaravelAiEmbeddingGenerator)->generate([]))->toBe([]);
});

it('fails a semantic search with the install hint when the sdk is missing', function (): void {
    $engine = new PHPVectorEngine(
        indexes: new IndexManager(fn (): array => (array) $this->app['config']->get('scout-phpvector', [])),
        embeddings: new LaravelAiEmbeddingGenerator,
        softDelete: false,
    );

    expect(fn (): array => $engine->search(Article::search('vector database')->semantic()))->toThrow(
        ScoutException::class,
        'Please install the [laravel/ai] package.',
    );
});
