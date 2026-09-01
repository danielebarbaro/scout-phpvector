<?php

declare(strict_types=1);

namespace DanieleBarbaro\ScoutPHPVector\Indexing;

use Closure;
use DanieleBarbaro\ScoutPHPVector\Exceptions\LockTimeoutException;
use PHPVector\Exception\LockTimeoutException as PHPVectorLockTimeoutException;
use PHPVector\Persistence\FileLock;

/**
 * Outer lock guarding a whole read-modify-write cycle on one index.
 *
 * PHPVector already locks each individual save() and open() with its own
 * FileLock and writes index files atomically, so this class does not
 * reimplement that: it reuses PHPVector\Persistence\FileLock on a *separate*
 * lock file, sitting one level up.
 *
 * The distinction matters. PHPVector guarantees that a single save() never
 * leaves a half-written folder behind. It cannot guarantee that two processes
 * which both open() an index, both add documents in memory and then both
 * save() do not lose one of the two sets of documents: the second save simply
 * overwrites the first. Scout's update()/delete() are exactly that kind of
 * read-modify-write, so the whole cycle has to be serialized here.
 *
 * The lock file is {root}/{index}.lock, deliberately distinct from PHPVector's
 * own {root}/{index}/.lock, because flock() on the same file from two handles
 * inside one process would deadlock.
 *
 * flock() is advisory and host-local: it protects concurrent php-fpm workers
 * and queue workers on the same machine, not an index shared over NFS or
 * between containers on different hosts.
 */
final class IndexLock
{
    private readonly FileLock $lock;

    public function __construct(
        private readonly string $index,
        string $file,
        private readonly float $timeout = FileLock::DEFAULT_TIMEOUT,
    ) {
        $this->lock = new FileLock($file);
    }

    /**
     * Run the callback while holding the lock, releasing it on every path.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(bool $exclusive, Closure $callback): mixed
    {
        try {
            $exclusive
                ? $this->lock->acquireExclusive($this->timeout)
                : $this->lock->acquireShared($this->timeout);
        } catch (PHPVectorLockTimeoutException $exception) {
            throw LockTimeoutException::forIndex($this->index, $this->timeout, $exception);
        }

        try {
            return $callback();
        } finally {
            $this->lock->release();
        }
    }
}
