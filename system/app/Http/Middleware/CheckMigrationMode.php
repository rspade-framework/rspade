<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class CheckMigrationMode
{
    protected $flag_file = '/var/www/html/.migrating';
    
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Only check migration mode in development environment
        if (!app()->environment('production') && file_exists($this->flag_file)) {
            $session_info = json_decode(file_get_contents($this->flag_file), true);
            $started_at = $session_info['started_at'] ?? 'unknown';
            
            // Create a detailed error message
            $message = "Database Migration in Progress\n\n";
            $message .= "A database migration is currently running.\n";
            $message .= "Started at: {$started_at}\n\n";
            $message .= "The application is temporarily unavailable to ensure data integrity.\n\n";
            $message .= "Please wait for the migration to complete.\n";
            $message .= "If this message persists, the migration may have been interrupted.\n";
            $message .= "Check the terminal running 'php artisan migrate' for status.";
            
            // Throw service unavailable exception
            throw new ServiceUnavailableHttpException(
                null,
                $message
            );
        }
        
        return $next($request);
    }
}