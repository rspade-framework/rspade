<?php

namespace App\RSpade\Integrations\Jqhtml;

use Illuminate\View\ViewException;

/**
 * Custom exception for JQHTML template compilation errors
 * Extends ViewException to integrate with Laravel's error handling
 */
#[Instantiatable]
class Jqhtml_Exception_ViewException extends ViewException
{
    protected ?int $column = null;
    protected ?string $context = null;
    protected ?string $suggestion = null;
    protected string $rawMessage = '';

    /**
     * Create a new JQHTML exception
     */
    public function __construct(string $message, string $file, int $line = 0, int $column = 0, ?\Throwable $previous = null)
    {
        // Store the raw message
        $this->rawMessage = $message;

        // Call parent constructor with the raw message
        // ViewException will handle the display
        parent::__construct($message, 0, 1, $file, $line, $previous);

        $this->column = $column;
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
     * Get the view source - override to handle .jqhtml files
     * Ignition uses this to display the source code preview
     */
    public function getSourceCode(): string
    {
        if (file_exists($this->getFile())) {
            return file_get_contents($this->getFile());
        }
        return '';
    }
}