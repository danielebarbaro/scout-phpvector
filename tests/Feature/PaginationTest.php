<?php

declare(strict_types=1);

use DanieleBarbaro\ScoutPHPVector\Tests\Fixtures\Article;

function seedTenArticles(): void
{
    $rows = [];

    for ($i = 1; $i <= 10; $i++) {
        $rows[] = [
            'title' => 'Vector '.$i,
            'body' => 'vector database php entry number '.$i,
            'views' => $i,
        ];
    }

    test()->seedArticles($rows);
}

it('slices the fetched window into pages', function (): void {
    seedTenArticles();

    $engine = $this->engine();

    $first = $engine->paginate(Article::search('vector'), 3, 1);
    $second = $engine->paginate(Article::search('vector'), 3, 2);

    expect($first['hits'])->toHaveCount(3)
        ->and($second['hits'])->toHaveCount(3)
        ->and(array_intersect(
            array_column($first['hits'], 'id'),
            array_column($second['hits'], 'id'),
        ))->toBe([]);
});

it('returns a shorter last page that reports an exact total', function (): void {
    seedTenArticles();

    $result = $this->engine()->paginate(Article::search('vector'), 4, 3);

    expect($result['hits'])->toHaveCount(2)
        ->and($result['total'])->toBe(10)
        ->and($result['exact_total'])->toBeTrue();
});

it('reports a lower bound total on an early page by default', function (): void {
    seedTenArticles();

    $result = $this->engine()->paginate(Article::search('vector'), 3, 1);

    // Default strategy fetches (page * perPage) + 1 hits, so the total is
    // "at least 4", not the real 10.
    expect($result['total'])->toBe(4)
        ->and($result['exact_total'])->toBeFalse();
});

it('reports the real count with the exact total strategy', function (): void {
    seedTenArticles();

    config()->set('scout-phpvector.pagination.exact_total', true);
    config()->set('scout-phpvector.pagination.max_total_hits', 100);

    $result = $this->freshEngine()->paginate(Article::search('vector'), 3, 1);

    expect($result['hits'])->toHaveCount(3)
        ->and($result['total'])->toBe(10)
        ->and($result['exact_total'])->toBeTrue();
});

it('builds a length aware paginator through scout', function (): void {
    seedTenArticles();

    config()->set('scout-phpvector.pagination.exact_total', true);

    $paginator = Article::search('vector')->paginate(4);

    expect($paginator->total())->toBe(10)
        ->and($paginator->items())->toHaveCount(4)
        ->and($paginator->currentPage())->toBe(1);
});

it('paginates a semantic search', function (): void {
    seedTenArticles();

    $result = $this->engine()->paginate(Article::search('vector database')->semantic(0.5), 4, 1);

    expect($result['hits'])->toHaveCount(4);
});
