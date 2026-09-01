<?php

declare(strict_types=1);

namespace DanieleBarbaro\ScoutPHPVector\Exceptions;

/**
 * Thrown when a Scout where() operator has no equivalent in PHPVector's
 * metadata filters. The engine never drops a constraint it cannot honour.
 */
final class UnsupportedOperatorException extends ScoutPHPVectorException
{
    /**
     * @param  list<string>  $supported
     */
    public static function forOperator(string $operator, string $field, array $supported): self
    {
        return new self(sprintf(
            'The where() operator [%s] used on field [%s] cannot be translated to a PHPVector metadata filter. '
            .'Supported operators are: %s. Filter the results in the database instead, via Scout\'s query() callback.',
            $operator,
            $field,
            implode(', ', $supported),
        ));
    }
}
