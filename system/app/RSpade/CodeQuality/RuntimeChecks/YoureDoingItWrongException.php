<?php

namespace App\RSpade\CodeQuality\RuntimeChecks;

use RuntimeException;

/**
 * Exception thrown when developers violate framework conventions
 *
 * This exception makes it immediately clear in stack traces that the error
 * is due to incorrect usage of the framework, not a bug in the framework itself.
 * The name is intentionally cheeky to be memorable and instructive.
 */
#[Instantiatable]
class YoureDoingItWrongException extends RuntimeException
{
    /**
     * Create a new YoureDoingItWrongException
     *
     * @param string $message The detailed error message explaining what's wrong and how to fix it
     * @param int $code Optional error code (default: 0)
     * @param \Throwable|null $previous Optional previous exception for chaining
     * @param string|null $file Optional file path to show as the error source
     * @param int|null $line Optional line number to show as the error source
     */
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null, ?string $file = null, ?int $line = null)
    {
        parent::__construct($message, $code, $previous);

        // Override the file and line if provided
        if ($file !== null) {
            $this->file = $file;
        }
        if ($line !== null) {
            $this->line = $line;
        }

        // Mark manifest as bad to force rebuild on next attempt
        \App\RSpade\Core\Manifest\Manifest::_set_manifest_is_bad();
    }
}