<?php
/**
 * Override Laravel's redirect helper to use relative URLs
 * This ensures redirects are protocol and domain agnostic
 */

namespace App\RSpade\Core\Helpers;

/**
 * Create a redirect response to the given path
 * Forces relative URLs regardless of APP_URL or request protocol
 * 
 * @param string $path
 * @param int $status
 * @param array $headers
 * @param bool|null $secure
 * @return \Illuminate\Http\RedirectResponse
 */
function rsx_redirect($path, $status = 302, $headers = [], $secure = null)
{
    // Ensure path starts with /
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }
    
    // Create a redirect response with just the path (no domain/protocol)
    $response = new \Illuminate\Http\RedirectResponse($path, $status, $headers);
    
    // Set the session on the response
    if (app()->bound('session')) {
        $response->setSession(app('session'));
    }
    
    return $response;
}