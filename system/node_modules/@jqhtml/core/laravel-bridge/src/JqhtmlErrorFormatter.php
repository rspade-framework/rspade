<?php

namespace Jqhtml\LaravelBridge;

use Throwable;

class JqhtmlErrorFormatter
{
    protected $sourceMapPath;
    protected $showSourceContext;
    protected $sourceMapCache = [];

    public function __construct(?string $sourceMapPath = null, bool $showSourceContext = true)
    {
        $this->sourceMapPath = $sourceMapPath;
        $this->showSourceContext = $showSourceContext;
    }

    /**
     * Format a JQHTML exception for Laravel's error handler
     */
    public function format(JqhtmlException $exception): array
    {
        $data = [
            'message' => $exception->getMessage(),
            'exception' => get_class($exception),
            'file' => $exception->getTemplateFile() ?? $exception->getFile(),
            'line' => $exception->getTemplateLine() ?? $exception->getLine(),
            'column' => $exception->getTemplateColumn(),
            'error_type' => $exception->getErrorType(),
        ];

        if ($exception->getSuggestion()) {
            $data['suggestion'] = $exception->getSuggestion();
        }

        if ($this->showSourceContext && $exception->getSourceCode()) {
            $data['source_context'] = $this->getSourceContext($exception);
        }

        // Try to resolve source map if we have a compiled file location
        if ($exception->getCompiledFile() && $this->sourceMapPath) {
            $data['source_map'] = $this->resolveSourceMap($exception);
        }

        return $data;
    }

    /**
     * Format for JSON responses
     */
    public function formatForJson(JqhtmlException $exception): array
    {
        return [
            'error' => true,
            'type' => 'jqhtml_error',
            'message' => $exception->getMessage(),
            'details' => $this->format($exception),
        ];
    }

    /**
     * Get source code context for display
     */
    protected function getSourceContext(JqhtmlException $exception): array
    {
        $source = $exception->getSourceCode();
        $line = $exception->getTemplateLine();
        $column = $exception->getTemplateColumn();

        if (!$source || !$line) {
            return [];
        }

        $lines = explode("\n", $source);
        $lineIndex = $line - 1;

        // Get 5 lines before and after for context
        $contextSize = 5;
        $start = max(0, $lineIndex - $contextSize);
        $end = min(count($lines) - 1, $lineIndex + $contextSize);

        $context = [];
        for ($i = $start; $i <= $end; $i++) {
            $context[] = [
                'line_number' => $i + 1,
                'content' => $lines[$i],
                'is_error_line' => $i === $lineIndex,
                'error_column' => $i === $lineIndex ? $column : null,
            ];
        }

        return $context;
    }

    /**
     * Attempt to resolve source map for better error location
     */
    protected function resolveSourceMap(JqhtmlException $exception): ?array
    {
        $compiledFile = $exception->getCompiledFile();
        if (!$compiledFile || !file_exists($compiledFile)) {
            return null;
        }

        // Look for source map file
        $mapFile = $compiledFile . '.map';
        if (!file_exists($mapFile)) {
            // Try in the configured source map directory
            if ($this->sourceMapPath) {
                $mapFile = $this->sourceMapPath . '/' . basename($compiledFile) . '.map';
                if (!file_exists($mapFile)) {
                    return null;
                }
            } else {
                return null;
            }
        }

        // Load and parse source map
        if (!isset($this->sourceMapCache[$mapFile])) {
            $mapContent = file_get_contents($mapFile);
            $this->sourceMapCache[$mapFile] = json_decode($mapContent, true);
        }

        $sourceMap = $this->sourceMapCache[$mapFile];
        if (!$sourceMap) {
            return null;
        }

        return [
            'version' => $sourceMap['version'] ?? null,
            'sources' => $sourceMap['sources'] ?? [],
            'file' => $sourceMap['file'] ?? null,
            'has_mappings' => !empty($sourceMap['mappings']),
        ];
    }

    /**
     * Convert a generic exception to JQHTML exception if it contains JQHTML error data
     */
    public function wrapException(Throwable $exception): Throwable
    {
        // Check if the exception message contains JQHTML error data
        $message = $exception->getMessage();

        // Look for JQHTML error patterns
        if (strpos($message, 'JQHTMLParseError') !== false ||
            strpos($message, 'at line') !== false && strpos($message, 'column') !== false) {

            // Try to extract error details from the message
            $templateFile = null;
            $templateLine = null;
            $templateColumn = null;

            // Extract location info from "at filename:line:column" format
            if (preg_match('/at\s+([^:]+):(\d+):(\d+)/', $message, $matches)) {
                $templateFile = $matches[1];
                $templateLine = (int)$matches[2];
                $templateColumn = (int)$matches[3];
            } elseif (preg_match('/at\s+line\s+(\d+),\s+column\s+(\d+)/', $message, $matches)) {
                $templateLine = (int)$matches[1];
                $templateColumn = (int)$matches[2];
            }

            // Extract suggestion if present
            $suggestion = null;
            if (preg_match('/Did you\s+(.+?)\?/', $message, $matches)) {
                $suggestion = 'Did you ' . $matches[1] . '?';
            }

            return new JqhtmlException(
                $message,
                $templateFile,
                $templateLine,
                $templateColumn,
                null,
                $suggestion,
                'parse',
                $exception->getCode(),
                $exception
            );
        }

        return $exception;
    }
}