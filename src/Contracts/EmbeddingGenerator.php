<?php

declare(strict_types=1);

namespace DanieleBarbaro\ScoutPHPVector\Contracts;

use Laravel\Scout\Exceptions\ScoutException;

interface EmbeddingGenerator
{
    /**
     * Turn the given inputs into dense vectors, preserving order.
     *
     * @param  list<string>  $inputs
     * @return list<list<float>>
     *
     * @throws ScoutException
     */
    public function generate(array $inputs): array;
}
