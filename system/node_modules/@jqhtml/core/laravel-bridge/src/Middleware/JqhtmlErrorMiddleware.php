<?php

namespace Jqhtml\LaravelBridge\Middleware;

use Closure;
use Illuminate\Http\Request;
use Jqhtml\LaravelBridge\JqhtmlErrorFormatter;
use Jqhtml\LaravelBridge\JqhtmlException;
use Throwable;

class JqhtmlErrorMiddleware
{
    protected $formatter;

    public function __construct(JqhtmlErrorFormatter $formatter)
    {
        $this->formatter = $formatter;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            return $next($request);
        } catch (Throwable $exception) {
            // Check if this might be a JQHTML error
            if ($this->isJqhtmlError($exception)) {
                // Wrap it in our exception type for better handling
                $wrapped = $this->formatter->wrapException($exception);

                if ($wrapped instanceof JqhtmlException) {
                    // Add request context
                    $wrapped->setCompiledFile(
                        $this->getCompiledFileFromRequest($request)
                    );

                    throw $wrapped;
                }
            }

            // Not a JQHTML error, rethrow as-is
            throw $exception;
        }
    }

    /**
     * Check if an exception appears to be JQHTML-related
     */
    protected function isJqhtmlError(Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return strpos($message, 'JQHTML') !== false ||
               strpos($message, 'jqhtml') !== false ||
               strpos($message, 'at line') !== false && strpos($message, 'column') !== false ||
               strpos($message, 'Unclosed component') !== false ||
               strpos($message, 'Mismatched tags') !== false;
    }

    /**
     * Try to determine the compiled file from the request
     */
    protected function getCompiledFileFromRequest(Request $request): ?string
    {
        // Check if the request has a reference to a compiled template
        $route = $request->route();

        if ($route && method_exists($route, 'getAction')) {
            $action = $route->getAction();

            // Look for JQHTML template reference in route action
            if (isset($action['jqhtml_template'])) {
                return $action['jqhtml_template'];
            }
        }

        // Check request attributes
        if ($request->has('_jqhtml_compiled')) {
            return $request->get('_jqhtml_compiled');
        }

        return null;
    }
}