<?php

declare(strict_types=1);

namespace DanieleBarbaro\ScoutPHPVector\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

/**
 * @property int $id
 * @property string $title
 * @property string $body
 * @property string $status
 * @property int $views
 */
final class Article extends Model
{
    use Searchable;

    protected $table = 'articles';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'views' => 'integer',
    ];

    public function searchableAs(): string
    {
        return 'articles';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status,
            'views' => (int) $this->views,
        ];
    }

    public function toSearchableEmbedding(): string
    {
        return $this->title.' '.$this->body;
    }
}
