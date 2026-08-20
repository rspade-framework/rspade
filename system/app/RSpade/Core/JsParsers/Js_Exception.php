<?php

namespace App\RSpade\Core\JsParsers;

use RuntimeException;

/**
 * Exception for JavaScript parsing and transformation errors
 */
#[Instantiatable]
class Js_Exception extends RuntimeException
{
    protected string $filePath;
    protected int $lineNumber = 0;
    protected ?int $column = null;
    protected ?string $context = null;
    protected ?string $suggestion = null;
    protected string $rawMessage = '';

    /**
     * Create a new JavaScript exception
     */
    public function __construct(string $message, string $file, int $line = 0, int $column = 0, ?\Throwable $previous = null)
    {
        // Store the raw message and properties
        $this->rawMessage = $message;
        $this->filePath = $file;
        $this->lineNumber = $line;
        $this->column = $column;

        // Clean up the file path for display
        $displayFile = str_replace(base_path() . '/', '', $file);

        // Build the display message
        $displayMessage = "JavaScript error in {$displayFile}";
        if ($line > 0) {
            $displayMessage .= " (line {$line}";
            if ($column > 0) {
                $displayMessage .= ":{$column}";
            }
            $displayMessage .= ")";
        }
        $displayMessage .= "\n{$message}";

        // Call parent constructor
        parent::__construct($displayMessage, 0, $previous);

        // Set file and line for PHP's getFile() and getLine()
        $this->file = $file;
        $this->line = $line;
    }

    /**
     * Get the column number
     */
    public function getColumn(): ?int
    {
        return $this->column;
    }

    /**
     * Set the column number
     */
    public function setColumn(int $column): void
    {
        $this->column = $column;
    }

    /**
     * Get the code context
     */
    public function getContext(): ?string
    {
        return $this->context;
    }

    /**
     * Set the code context
     */
    public function setContext(string $context): void
    {
        $this->context = $context;
    }

    /**
     * Get the suggestion
     */
    public function getSuggestion(): ?string
    {
        return $this->suggestion;
    }

    /**
     * Set the suggestion
     */
    public function setSuggestion(string $suggestion): void
    {
        $this->suggestion = $suggestion;
    }

    /**
     * Get the raw unformatted message
     */
    public function getRawMessage(): string
    {
        return $this->rawMessage;
    }

    /**
     * Get the file path
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * Get the line number
     */
    public function getLineNumber(): int
    {
        return $this->lineNumber;
    }
}