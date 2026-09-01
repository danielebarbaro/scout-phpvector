<?php

declare(strict_types=1);

namespace DanieleBarbaro\ScoutPHPVector\Exceptions;

use Throwable;

/**
 * Thrown when an index lock could not be acquired within the configured
 * timeout, either by this package's outer read-modify-write lock or by
 * PHPVector's own per-file lock.
 *
 * Seeing this in production usually means several processes are writing the
 * same index concurrently: move indexing to a queue and guard the job with
 * WithoutOverlapping.
 */
final class LockTimeoutException extends ScoutPHPVectorException
{
    public static function forIndex(string $index, float $timeout, ?Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'Timed out after %.2Fs waiting for the write lock on the PHPVector index [%s]. '
                .'Serialize indexing through a queue and guard the job with WithoutOverlapping, '
                .'or raise [scout-phpvector.lock.timeout].',
                $timeout,
                $index,
            ),
            0,
            $previous,
        );
    }
}
