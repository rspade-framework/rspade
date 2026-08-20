<?php

namespace App\RSpade\Core\Debug;

use RuntimeException;
use Throwable;

/**
 * Exception for errors at a specific file and line number
 *
 * Used to represent errors that occur at a specific file location different
 * from where the exception is thrown. Common use cases:
 * - Syntax errors detected in custom parsers/linters
 * - Template compilation errors pointing to template files
 * - Configuration file validation errors
 * - Any custom validation where the error location differs from detection location
 *
 * The file and line parameters override the exception's built-in file/line tracking,
 * allowing error handlers (like Ignition) to show the correct file preview.
 */
#[Instantiatable]
class Rsx_File_Exception extends RuntimeException
{
    /**
     * Create a new Rsx_File_Exception
     *
     * @param string $message The detailed error message
     * @param int $code Optional error code (default: 0)
     * @param Throwable|null $previous Optional previous exception for chaining
     * @param string|null $file Optional file path to show as the error source
     * @param int|null $line Optional line number to show as the error source
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, ?string $file = null, ?int $line = null)
    {
        parent::__construct($message, $code, $previous);

        // Override the file and line if provided
        if ($file !== null) {
            $this->file = $file;
        }
        if ($line !== null) {
            $this->line = $line;
        }
    }
}
