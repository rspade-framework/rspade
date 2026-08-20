<?php

/**
 * JQHTML Laravel Bridge Autoloader
 *
 * Include this file from your Laravel project to load the JQHTML error handling bridge:
 *
 *   require_once base_path('node_modules/@jqhtml/core/laravel-bridge/autoload.php');
 *
 * Or if using in a service provider:
 *
 *   require_once dirname(__DIR__, 3) . '/node_modules/@jqhtml/core/laravel-bridge/autoload.php';
 */

// This is a Laravel bridge - it REQUIRES Laravel to function
if (!class_exists('Illuminate\\Support\\ServiceProvider')) {
    throw new \RuntimeException(
        'JQHTML Laravel Bridge requires Laravel. ' .
        'This file should only be included from within a Laravel application.'
    );
}

// Register the autoloader for JQHTML Laravel Bridge classes
spl_autoload_register(function ($class) {
    // Check if the class is in the Jqhtml\LaravelBridge namespace
    $prefix = 'Jqhtml\\LaravelBridge\\';
    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Replace namespace separators with directory separators
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative_class) . '.php';

    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});

// Auto-register the service provider if in Laravel application context
if (function_exists('app') && app() instanceof \Illuminate\Foundation\Application) {
    app()->register(\Jqhtml\LaravelBridge\JqhtmlServiceProvider::class);
}

// Return the namespace for convenience
return 'Jqhtml\\LaravelBridge';