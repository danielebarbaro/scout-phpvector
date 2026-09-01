<?php

declare(strict_types=1);

use DanieleBarbaro\ScoutPHPVector\Tests\Fixtures\Article;
use PHPVector\Metadata\MetadataFilter;
use PHPVector\Metadata\SortDirection;
use PHPVector\VectorDatabase;

/*
 * Covers the escape hatch documented in the README: Scout's search callback
 * receives the VectorDatabase and returns PHPVector SearchResult objects.
 */

it('hands the vector database to the scout callback', function (): void {
    $this->seedArticles([
        ['title' => 'Quiet', 'body' => 'vector database php', 'views' => 10],
        ['title' => 'Popular', 'body' => 'vector database php', 'views' => 5000],
    ]);

    $results = Article::search('anything', function (VectorDatabase $db, string $query, array $options): array {
        expect($query)->toBe('anything')
            ->and($options['index'])->toBe('articles')
            ->and($options['k'])->toBeInt();

        return $db->metadataSearch(
            filters: [MetadataFilter::gte('views', 1000)],
            limit: $options['k'],
            sortBy: 'views',
            sortDirection: SortDirection::Desc,
        );
    })->get();

    expect($results->pluck('title')->all())->toBe(['Popular']);
});
