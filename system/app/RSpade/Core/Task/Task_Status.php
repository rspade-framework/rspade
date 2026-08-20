<?php

namespace App\RSpade\Core\Task;

/**
 * Task_Status
 *
 * Value object representing task execution status.
 * Provides type-safe status constants and helper methods.
 */
#[Instantiatable]
class Task_Status
{
    // Status constants
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const KILLED = 'killed';

    private string $status;

    public function __construct(string $status)
    {
        if (!in_array($status, [self::PENDING, self::RUNNING, self::COMPLETED, self::FAILED, self::KILLED])) {
            throw new \InvalidArgumentException("Invalid task status: {$status}");
        }

        $this->status = $status;
    }

    public function value(): string
    {
        return $this->status;
    }

    public function is_pending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function is_running(): bool
    {
        return $this->status === self::RUNNING;
    }

    public function is_completed(): bool
    {
        return $this->status === self::COMPLETED;
    }

    public function is_failed(): bool
    {
        return $this->status === self::FAILED;
    }

    public function is_killed(): bool
    {
        return $this->status === self::KILLED;
    }

    public function is_terminal(): bool
    {
        return $this->is_completed() || $this->is_failed() || $this->is_killed();
    }

    public function __toString(): string
    {
        return $this->status;
    }
}
