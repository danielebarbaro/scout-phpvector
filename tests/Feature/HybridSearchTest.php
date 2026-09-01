<?php

declare(strict_types=1);

use DanieleBarbaro\ScoutPHPVector\Exceptions\ScoutPHPVectorException;
use DanieleBarbaro\ScoutPHPVector\Tests\Fixtures\Article;
use Laravel\Scout\Builder;

/**
 * The fixture is built so the two legs disagree:
 *
 *   query "recipes vector"
 *   BM25 leg   -> "Recipes" wins, it repeats the token "recipes" four times
 *                 and "vector" never appears in it.
 *   vector leg -> "Vectors" wins, the fake embedder maps the query onto the
 *                 "vector" dimension and "Recipes" has zero overlap.
 *
 * Any fusion bug therefore shows up as a change in which row comes first.
 */
function seedDisagreeingArticles(): void
{
    test()->seedArticles([
        ['title' => 'Recipes', 'body' => 'recipes recipes recipes cat'],
        ['title' => 'Vectors', 'body' => 'vector database php'],
    ]);
}

function topTitle(Builder $builder): string
{
    $first = $builder->get()->first();

    expect($first)->not->toBeNull('The search returned no results.');

    return (string) $first->title;
}

it('proves the two legs disagree on the fixture', function (): void {
    seedDisagreeingArticles();

    expect(topTitle(Article::search('recipes vector')))->toBe('Recipes')
        ->and(topTitle(Article::search('recipes vector')->semantic(0)))->toBe('Vectors');
});

it('reproduces the bm25 ranking with a heavy text weight', function (): void {
    seedDisagreeingArticles();

    expect(topTitle(Article::search('recipes vector')->hybrid(10, 1)))->toBe('Recipes');
});

it('reproduces the vector ranking with a heavy semantic weight', function (): void {
    seedDisagreeingArticles();

    expect(topTitle(Article::search('recipes vector')->hybrid(1, 10)))->toBe('Vectors');
});

it('returns both legs candidates', function (): void {
    seedDisagreeingArticles();

    expect(Article::search('recipes vector')->hybrid()->get())->toHaveCount(2);
});

it('applies metadata filters to a hybrid search', function (): void {
    $this->seedArticles([
        ['title' => 'Recipes', 'body' => 'recipes recipes cat', 'status' => 'draft'],
        ['title' => 'Vectors', 'body' => 'vector database php', 'status' => 'published'],
    ]);

    $results = Article::search('recipes vector')->hybrid()->where('status', 'published')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->title)->toBe('Vectors');
});

it('refuses a minimum similarity on a hybrid search', function (): void {
    seedDisagreeingArticles();

    expect(fn () => Article::search('recipes vector')->hybrid(1, 1, 0.7)->get())
        ->toThrow(ScoutPHPVectorException::class, 'no per-leg');
});

it('refuses unequal weights when the fusion mode is rrf', function (): void {
    seedDisagreeingArticles();

    config()->set('scout-phpvector.hybrid.mode', 'rrf');

    expect(fn (): array => $this->freshEngine()->search(Article::search('recipes vector')->hybrid(3, 1)))
        ->toThrow(ScoutPHPVectorException::class, 'Reciprocal Rank Fusion is rank based');
});

it('accepts equal weights when the fusion mode is rrf', function (): void {
    seedDisagreeingArticles();

    config()->set('scout-phpvector.hybrid.mode', 'rrf');

    $hits = $this->freshEngine()->search(Article::search('recipes vector')->hybrid())['hits'];

    expect($hits)->toHaveCount(2);
});

it('rejects an unknown fusion mode', function (): void {
    seedDisagreeingArticles();

    config()->set('scout-phpvector.hybrid.mode', 'magic');

    expect(fn (): array => $this->freshEngine()->search(Article::search('recipes vector')->hybrid()))
        ->toThrow(ScoutPHPVectorException::class, 'Unknown hybrid fusion mode');
});
