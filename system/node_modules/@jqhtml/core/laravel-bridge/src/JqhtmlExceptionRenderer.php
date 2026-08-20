<?php

namespace Jqhtml\LaravelBridge;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class JqhtmlExceptionRenderer
{
    protected $formatter;

    public function __construct(JqhtmlErrorFormatter $formatter)
    {
        $this->formatter = $formatter;
    }

    /**
     * Render a JQHTML exception for display
     */
    public function render(Request $request, JqhtmlException $exception): ?Response
    {
        if ($request->expectsJson()) {
            return $this->renderJson($exception);
        }

        // For development, enhance the error page with JQHTML-specific info
        if (app()->hasDebugModeEnabled()) {
            return $this->renderDebugView($exception);
        }

        // In production, return null to let Laravel handle it normally
        return null;
    }

    /**
     * Render JSON error response
     */
    protected function renderJson(JqhtmlException $exception): JsonResponse
    {
        return response()->json(
            $this->formatter->formatForJson($exception),
            500
        );
    }

    /**
     * Render debug view with enhanced JQHTML error information
     */
    protected function renderDebugView(JqhtmlException $exception): ?Response
    {
        // Get formatted error data
        $errorData = $this->formatter->format($exception);

        // If using Laravel's Ignition error page, enhance it with our data
        if (class_exists(\Spatie\LaravelIgnition\Facades\Flare::class)) {
            \Spatie\LaravelIgnition\Facades\Flare::context('JQHTML Error', $errorData);
            return null; // Let Ignition handle the rendering
        }

        // If using older Laravel or custom error handling
        try {
            // Check if we have a custom view
            if (view()->exists('jqhtml::error')) {
                return response()->view('jqhtml::error', [
                    'exception' => $exception,
                    'error_data' => $errorData,
                ], 500);
            }
        } catch (Throwable $e) {
            // Fall back to letting Laravel handle it
        }

        return null;
    }

    /**
     * Check if an exception should be handled by this renderer
     */
    public function shouldHandle(Throwable $exception): bool
    {
        if ($exception instanceof JqhtmlException) {
            return true;
        }

        // Check if it's a wrapped JQHTML error
        $message = $exception->getMessage();
        return strpos($message, 'JQHTMLParseError') !== false ||
               strpos($message, 'JQHTML') !== false;
    }
}