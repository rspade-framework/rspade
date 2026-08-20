<?php

namespace Jqhtml\LaravelBridge;

use Illuminate\View\ViewException;
use Throwable;

class JqhtmlException extends ViewException
{
    protected $templateFile;
    protected $templateLine;
    protected $templateColumn;
    protected $sourceCode;
    protected $suggestion;
    protected $errorType;
    protected $compiledFile;

    public function __construct(
        string $message,
        ?string $templateFile = null,
        ?int $templateLine = null,
        ?int $templateColumn = null,
        ?string $sourceCode = null,
        ?string $suggestion = null,
        string $errorType = 'parse',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        // Call ViewException constructor with template file and line info
        // ViewException signature: __construct($message, $code = 0, $severity = 1, $filename = '', $lineno = 0, $previous = null)
        parent::__construct(
            $message,
            $code,
            1, // severity
            $templateFile ?? '',
            $templateLine ?? 0,
            $previous
        );

        $this->templateFile = $templateFile;
        $this->templateLine = $templateLine;
        $this->templateColumn = $templateColumn;
        $this->sourceCode = $sourceCode;
        $this->suggestion = $suggestion;
        $this->errorType = $errorType;
    }

    /**
     * Create from a JavaScript error object or JSON string
     */
    public static function createFromJsError($jsError, ?string $compiledFile = null): self
    {
        if (is_string($jsError)) {
            $jsError = json_decode($jsError, true);
        }

        return new self(
            $jsError['message'] ?? 'Unknown JQHTML error',
            $jsError['filename'] ?? $jsError['templateFile'] ?? null,
            $jsError['line'] ?? null,
            $jsError['column'] ?? null,
            $jsError['source'] ?? null,
            $jsError['suggestion'] ?? null,
            $jsError['severity'] ?? 'error'
        );
    }

    public function getTemplateFile(): ?string
    {
        return $this->templateFile;
    }

    public function getTemplateLine(): ?int
    {
        return $this->templateLine;
    }

    public function getTemplateColumn(): ?int
    {
        return $this->templateColumn;
    }

    public function getSourceCode(): ?string
    {
        return $this->sourceCode;
    }

    public function getSuggestion(): ?string
    {
        return $this->suggestion;
    }

    public function getErrorType(): string
    {
        return $this->errorType;
    }

    public function getCompiledFile(): ?string
    {
        return $this->compiledFile;
    }

    public function setCompiledFile(string $file): self
    {
        $this->compiledFile = $file;
        return $this;
    }

    /**
     * Get formatted error message with context
     */
    public function getFormattedMessage(): string
    {
        $message = $this->getMessage();

        if ($this->suggestion) {
            $message .= "\n" . $this->suggestion;
        }

        if ($this->templateFile) {
            $message .= sprintf(
                "\n  at %s:%d:%d",
                $this->templateFile,
                $this->templateLine ?? 0,
                $this->templateColumn ?? 0
            );
        }

        if ($this->sourceCode && $this->templateLine) {
            $message .= "\n\n" . $this->getCodeSnippet();
        }

        return $message;
    }

    /**
     * Get code snippet with error highlighting
     */
    protected function getCodeSnippet(): string
    {
        if (!$this->sourceCode || !$this->templateLine) {
            return '';
        }

        $lines = explode("\n", $this->sourceCode);
        $lineIndex = $this->templateLine - 1;

        // Show 3 lines before and after for context
        $contextLines = 3;
        $startLine = max(0, $lineIndex - $contextLines);
        $endLine = min(count($lines) - 1, $lineIndex + $contextLines);

        $snippet = '';
        for ($i = $startLine; $i <= $endLine; $i++) {
            $lineNum = $i + 1;
            $isErrorLine = $i === $lineIndex;
            $prefix = $isErrorLine ? '>' : ' ';

            // Line number with padding
            $lineNumStr = str_pad((string)$lineNum, 5, ' ', STR_PAD_LEFT);
            $snippet .= sprintf("%s %s | %s\n", $prefix, $lineNumStr, $lines[$i]);

            // Add pointer to error column
            if ($isErrorLine && $this->templateColumn) {
                $spaces = str_repeat(' ', $this->templateColumn + 8);
                $carets = str_repeat('^', min(strlen($lines[$i]) - $this->templateColumn + 1, 20));
                $snippet .= $spaces . $carets . "\n";
            }
        }

        return $snippet;
    }
}