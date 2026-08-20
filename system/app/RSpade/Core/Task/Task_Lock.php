<?php

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;

/**
 * Task_Lock
 *
 * Manages MySQL advisory locks for atomic task queue operations.
 * Uses GET_LOCK() and RELEASE_LOCK() for distributed locking.
 */
#[Instantiatable]
class Task_Lock
{
    private string $lock_name;
    private int $timeout;
    private bool $is_locked = false;

    /**
     * Create a new task lock instance
     *
     * @param string $lock_name Unique lock identifier
     * @param int $timeout Lock timeout in seconds (default: 10)
     */
    public function __construct(string $lock_name, int $timeout = 10)
    {
        $this->lock_name = $lock_name;
        $this->timeout = $timeout;
    }

    /**
     * Acquire the lock
     *
     * @return bool True if lock acquired, false if already held by another process
     */
    public function acquire(): bool
    {
        if ($this->is_locked) {
            return true;
        }

        $result = DB::selectOne("SELECT GET_LOCK(?, ?) as acquired", [
            $this->lock_name,
            $this->timeout,
        ]);

        $this->is_locked = (bool) $result->acquired;

        return $this->is_locked;
    }

    /**
     * Release the lock
     *
     * @return bool True if lock was released, false if lock was not held
     */
    public function release(): bool
    {
        if (!$this->is_locked) {
            return false;
        }

        $result = DB::selectOne("SELECT RELEASE_LOCK(?) as released", [
            $this->lock_name,
        ]);

        $was_released = (bool) $result->released;

        if ($was_released) {
            $this->is_locked = false;
        }

        return $was_released;
    }

    /**
     * Check if the lock is currently held by this instance
     *
     * @return bool
     */
    public function is_locked(): bool
    {
        return $this->is_locked;
    }

    /**
     * Check if the lock is currently held by ANY process
     *
     * @return bool
     */
    public function is_in_use(): bool
    {
        $result = DB::selectOne("SELECT IS_USED_LOCK(?) as in_use", [
            $this->lock_name,
        ]);

        return $result->in_use !== null;
    }

    /**
     * Automatically release lock on destruction
     */
    public function __destruct()
    {
        if ($this->is_locked) {
            $this->release();
        }
    }
}
