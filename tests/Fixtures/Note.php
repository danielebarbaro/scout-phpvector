<?php

declare(strict_types=1);

namespace DanieleBarbaro\ScoutPHPVector\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

/**
 * A searchable model without toSearchableEmbedding(), used to prove that
 * semantic search fails loudly instead of returning meaningless results.
 *
 * @property int $id
 * @property string $body
 */
final class Note extends Model
{
    use Searchable;

    protected $table = 'notes';

    protected $guarded = [];

    public $timestamps = false;

    public function searchableAs(): string
    {
        return 'notes';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
        ];
    }
}
