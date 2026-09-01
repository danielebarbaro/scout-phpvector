<?php

declare(strict_types=1);

use DanieleBarbaro\ScoutPHPVector\Exceptions\UnsupportedOperatorException;
use DanieleBarbaro\ScoutPHPVector\Search\FilterTranslator;
use DanieleBarbaro\ScoutPHPVector\Tests\Fixtures\Article;
use PHPVector\Metadata\MetadataFilter;

function seedCatalogue(): void
{
    test()->seedArticles([
        ['title' => 'Php one', 'body' => 'php one', 'status' => 'published', 'views' => 10],
        ['title' => 'Php two', 'body' => 'php two', 'status' => 'draft', 'views' => 50],
        ['title' => 'Php three', 'body' => 'php three', 'status' => 'archived', 'views' => 100],
    ]);
}

it('translates every supported operator', function (): void {
    $builder = Article::search('php')
        ->where('status', 'published')
        ->where('views', '>=', 10)
        ->where('views', '<', 100)
        ->where('title', '!=', 'Php two')
        ->whereIn('status', ['published', 'draft'])
        ->whereNotIn('title', ['Php three']);

    $filters = (new FilterTranslator)->translate($builder);

    expect($filters)->toContainOnlyInstancesOf(MetadataFilter::class)
        ->and(array_map(static fn (MetadataFilter $filter): string => $filter->operator, $filters))
        ->toBe(['=', '>=', '<', '!=', 'in', 'not_in'])
        ->and(array_map(static fn (MetadataFilter $filter): string => $filter->key, $filters))
        ->toBe(['status', 'views', 'views', 'title', 'status', 'title']);
});

it('filters with an equality constraint', function (): void {
    seedCatalogue();

    $results = Article::search('php')->where('status', 'draft')->get();

    expect($results->pluck('title')->all())->toBe(['Php two']);
});

it('filters with a comparison constraint', function (): void {
    seedCatalogue();

    $results = Article::search('php')->where('views', '>', 10)->get();

    expect($results->pluck('title')->all())->toEqualCanonicalizing(['Php two', 'Php three']);
});

it('filters with where in and where not in', function (): void {
    seedCatalogue();

    $in = Article::search('php')->whereIn('status', ['published', 'archived'])->get();
    $notIn = Article::search('php')->whereNotIn('status', ['published', 'archived'])->get();

    expect($in->pluck('title')->all())->toEqualCanonicalizing(['Php one', 'Php three'])
        ->and($notIn->pluck('title')->all())->toBe(['Php two']);
});

it('throws instead of dropping an operator it cannot translate', function (): void {
    $builder = Article::search('php')->where('title', 'like', '%php%');

    expect(fn (): array => (new FilterTranslator)->translate($builder))
        ->toThrow(UnsupportedOperatorException::class, 'The where() operator [like] used on field [title]');
});

it('compares metadata strictly about types', function (): void {
    seedCatalogue();

    // "views" is stored as an integer, so a string comparison finds nothing.
    // This mirrors PHPVector's strict === evaluation and is documented.
    expect(Article::search('php')->where('views', 50)->get())->toHaveCount(1)
        ->and(Article::search('php')->where('views', '50')->get())->toHaveCount(0);
});
