<?php

namespace App\RSpade\Core\Debug;

use RuntimeException;
use Throwable;

/**
 * Exception that reports error location from the caller's perspective
 *
 * Automatically walks back the stack trace to show the error at the calling code's location
 * instead of where the exception was thrown. Useful for utility methods that validate inputs
 * and want errors to point to the caller's location.
 *
 * Common use cases:
 * - Rsx::Route() when controller class doesn't exist - show the blade template line
 * - Validation helpers that want to show caller's code as error source
 * - Any utility method where the bug is in the caller's usage, not the utility itself
 *
 * Example:
 * ```php
 * // In Rsx.php
 * throw new Rsx_Caller_Exception("Class {$class_name} not found in manifest");
 * // Error shows: frontend_layout.blade.php:23 instead of Rsx.php:242
 * ```
 */
#[Instantiatable]
class Rsx_Caller_Exception extends RuntimeException
{
    /**
     * Create a new Rsx_Caller_Exception
     *
     * Automatically determines the caller's file and line by walking back the stack trace.
     * By default, goes back 1 frame (immediate caller). Increase $frames_back for deeper utilities.
     *
     * @param string $message The detailed error message
     * @param int $frames_back Number of stack frames to walk back (default: 1 = immediate caller)
     * @param int $code Optional error code (default: 0)
     * @param Throwable|null $previous Optional previous exception for chaining
     */
    public function __construct(string $message = '', int $frames_back = 1, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);

        // Get the stack trace to find the caller
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $frames_back + 2);

        // Walk back to the desired frame
        // Frame 0 is this constructor
        // Frame 1 is the immediate caller (default)
        // Frame 2+ is deeper callers
        $target_frame = $trace[$frames_back] ?? null;

        if ($target_frame !== null) {
            // Override file and line to point to the caller
            if (isset($target_frame['file'])) {
                $this->file = $target_frame['file'];
            }
            if (isset($target_frame['line'])) {
                $this->line = $target_frame['line'];
            }
        }
    }
}
