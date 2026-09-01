<?php

declare(strict_types=1);

use DanieleBarbaro\ScoutPHPVector\Exceptions\ScoutPHPVectorException;
use DanieleBarbaro\ScoutPHPVector\Tests\Fixtures\Article;
use DanieleBarbaro\ScoutPHPVector\Tests\Fixtures\Note;

/**
 * The fake embedder projects text onto a fixed vocabulary, so cosine
 * similarity is the vocabulary overlap:
 *
 *   "php vector database"  vs query "vector database" -> 2 / sqrt(3 * 2) = 0.816
 *   "cat dog"              vs query "vector database" -> 0
 */
function seedVocabularyArticles(): void
{
    test()->seedArticles([
        ['title' => 'Storage', 'body' => 'php vector database'],
        ['title' => 'Pets', 'body' => 'cat dog'],
        ['title' => 'Food', 'body' => 'pizza pasta'],
    ]);
}

it('finds semantically close documents without a shared keyword', function (): void {
    seedVocabularyArticles();

    // "database" alone never appears in the pets or food rows, and BM25 on
    // its own would rank nothing else; the vector leg still scores them.
    $results = Article::search('vector database')->semantic()->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->title)->toBe('Storage');
});

it('applies the default minimum similarity of zero point six', function (): void {
    seedVocabularyArticles();

    $engine = $this->engine();
    $builder = Article::search('vector database')->semantic();

    $hits = $engine->search($builder)['hits'];

    expect($hits)->toHaveCount(1)
        ->and($hits[0]['score'])->toBeGreaterThanOrEqual(0.6)
        ->and($hits[0]['score'])->toEqualWithDelta(0.8164, 0.001);
});

it('drops results below an explicit threshold', function (): void {
    seedVocabularyArticles();

    expect(Article::search('vector database')->semantic(0.8)->get())->toHaveCount(1)
        ->and(Article::search('vector database')->semantic(0.9)->get())->toHaveCount(0);
});

it('keeps weak matches when the threshold is zero', function (): void {
    seedVocabularyArticles();

    expect(Article::search('vector database')->semantic(0)->get())->toHaveCount(3);
});

it('rejects a minimum similarity outside zero to one', function (): void {
    seedVocabularyArticles();

    expect(fn () => Article::search('vector database')->semantic(1.5)->get())
        ->toThrow(ScoutPHPVectorException::class, 'The minimum similarity must be between 0 and 1.');
});

it('refuses a semantic search on a model without an embedding hook', function (): void {
    $this->seedNote('a note about vectors');

    expect(fn () => Note::search('vectors')->semantic()->get())
        ->toThrow(ScoutPHPVectorException::class, 'toSearchableEmbedding');
});

it('combines a semantic search with metadata filters', function (): void {
    $this->seedArticles([
        ['title' => 'Public storage', 'body' => 'php vector database', 'status' => 'published'],
        ['title' => 'Draft storage', 'body' => 'php vector database', 'status' => 'draft'],
    ]);

    $results = Article::search('vector database')->semantic()->where('status', 'published')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->title)->toBe('Public storage');
});

it('exposes the score and rank as scout metadata', function (): void {
    seedVocabularyArticles();

    $article = Article::search('vector database')->semantic()->get()->first();

    expect($article->scoutMetadata()['_rank'])->toBe(1)
        ->and($article->scoutMetadata()['_score'])->toBeFloat();
});

it('rejects a similarity threshold when the index uses dot product', function (): void {
    config()->set('scout-phpvector.distance', 'dot_product');

    expect(fn (): array => $this->freshEngine()->search(Article::search('vector database')->semantic(0.7)))
        ->toThrow(ScoutPHPVectorException::class, 'dot_product');
});

it('also rejects the default threshold on a dot product index', function (): void {
    config()->set('scout-phpvector.distance', 'dot_product');

    expect(fn (): array => $this->freshEngine()->search(Article::search('vector database')->semantic()))
        ->toThrow(ScoutPHPVectorException::class, 'unbounded scores');
});

it('allows a zero threshold on a dot product index', function (): void {
    config()->set('scout-phpvector.distance', 'dot_product');

    $hits = $this->freshEngine()->search(Article::search('vector database')->semantic(0))['hits'];

    expect($hits)->toBe([]);
});
