<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\RSpade\Core\Debug\Debugger;

class PlaywrightTestMode
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Only process Playwright headers in non-production environments
        // and only from loopback addresses (no proxy headers)
        if (app()->environment('production') || !is_loopback_ip()) {
            return $next($request);
        }

        // If this is a Playwright test request, configure console debug from headers
        if ($request->hasHeader('X-Playwright-Test')) {
            // Configure console debug settings from headers
            Debugger::configure_from_headers();
            $response = $next($request);
            
            // If this is a redirect response, modify the Location header to be relative
            if ($response->isRedirect()) {
                $location = $response->headers->get('Location');
                
                // Parse the URL and extract just the path and query
                $parsed = parse_url($location);
                if (isset($parsed['path'])) {
                    $relative_url = $parsed['path'];
                    if (isset($parsed['query'])) {
                        $relative_url .= '?' . $parsed['query'];
                    }
                    if (isset($parsed['fragment'])) {
                        $relative_url .= '#' . $parsed['fragment'];
                    }
                    
                    // Only modify if it's a local redirect (not external)
                    if (!isset($parsed['host']) || $parsed['host'] === $request->getHost() || $parsed['host'] === '127.0.0.1' || $parsed['host'] === 'localhost') {
                        $response->headers->set('Location', $relative_url);
                    }
                }
            }
            
            return $response;
        }
        
        return $next($request);
    }
}