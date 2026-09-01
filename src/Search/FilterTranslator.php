<?php

declare(strict_types=1);

namespace DanieleBarbaro\ScoutPHPVector\Search;

use DanieleBarbaro\ScoutPHPVector\Exceptions\ScoutPHPVectorException;
use DanieleBarbaro\ScoutPHPVector\Exceptions\UnsupportedOperatorException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;
use PHPVector\Metadata\MetadataFilter;

/**
 * Translates Scout's where / whereIn / whereNotIn constraints into PHPVector
 * metadata filters.
 *
 * Every returned filter is ANDed by PHPVector. An operator without an
 * equivalent raises instead of being dropped: a search that silently ignores a
 * constraint returns wrong rows, which is worse than a failure.
 */
final class FilterTranslator
{
    /**
     * Scout operator to PHPVector operator.
     *
     * @var array<string, string>
     */
    private const array OPERATORS = [
        '=' => '=',
        '==' => '=',
        '!=' => '!=',
        '<>' => '!=',
        '<' => '<',
        '<=' => '<=',
        '>' => '>',
        '>=' => '>=',
    ];

    /**
     * @param  Builder<Model>  $builder
     * @return list<MetadataFilter>
     */
    public function translate(Builder $builder): array
    {
        $filters = [];

        foreach ($builder->wheres as $where) {
            $filters[] = $this->translateWhere($where);
        }

        foreach ($builder->whereIns as $field => $values) {
            $filters[] = MetadataFilter::in((string) $field, $this->values($values));
        }

        foreach ($builder->whereNotIns as $field => $values) {
            $filters[] = MetadataFilter::notIn((string) $field, $this->values($values));
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>|mixed  $where
     */
    private function translateWhere(mixed $where): MetadataFilter
    {
        if (! is_array($where) || ! array_key_exists('field', $where)) {
            throw new ScoutPHPVectorException(
                'Unexpected Scout where() constraint: expected an array with a [field] key.',
            );
        }

        $field = (string) $where['field'];
        $operator = (string) ($where['operator'] ?? '=');
        $value = $where['value'] ?? null;

        if (! array_key_exists($operator, self::OPERATORS)) {
            throw UnsupportedOperatorException::forOperator(
                $operator,
                $field,
                array_values(array_unique(array_keys(self::OPERATORS))),
            );
        }

        return new MetadataFilter($field, $value, self::OPERATORS[$operator]);
    }

    /**
     * @return list<mixed>
     */
    private function values(mixed $values): array
    {
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        if (! is_array($values)) {
            throw new ScoutPHPVectorException(
                'whereIn() and whereNotIn() require an array or an Arrayable of values.',
            );
        }

        return array_values($values);
    }
}
