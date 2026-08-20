# JQHTML Laravel Bridge

Laravel integration package for JQHTML template error reporting with source map support.

## Installation

```bash
composer require jqhtml/laravel-bridge
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=jqhtml-config
```

## Usage

### Basic Setup

The package automatically registers its service provider and exception handler extensions. No additional setup is required for basic functionality.

### Error Handling

When a JQHTML template error occurs, the package will:

1. Parse the error message to extract template location information
2. Load source maps if available
3. Format the error for Laravel's exception handler
4. Display enhanced error information in debug mode

### Middleware

To automatically catch and format JQHTML errors, add the middleware to your route groups:

```php
// In app/Http/Kernel.php
protected $middlewareGroups = [
    'web' => [
        // ...
        \Jqhtml\LaravelBridge\Middleware\JqhtmlErrorMiddleware::class,
    ],
];
```

### Manual Exception Creation

```php
use Jqhtml\LaravelBridge\JqhtmlException;

// Create from JavaScript error data
$jsError = json_decode($errorJson, true);
$exception = JqhtmlException::createFromJsError($jsError);

// Create manually
$exception = new JqhtmlException(
    'Unclosed component definition',
    'templates/user-card.jqhtml',  // template file
    42,                             // line number
    15,                             // column number
    $sourceCode,                    // template source
    'Did you forget </Define:UserCard>?'  // suggestion
);
```

### Integration with Node.js Compiler

When compiling JQHTML templates with Node.js, catch errors and pass them to Laravel:

```javascript
// Node.js side
try {
    const compiled = jqhtml.compile(template);
} catch (error) {
    // Send error details to Laravel
    const errorData = {
        message: error.message,
        filename: error.filename,
        line: error.line,
        column: error.column,
        source: error.source,
        suggestion: error.suggestion
    };

    // Pass to Laravel via API or process communication
    sendToLaravel(errorData);
}
```

```php
// Laravel side - Best Practice
use Jqhtml\LaravelBridge\JqhtmlException;

// Parse compiler JSON output
$errorData = json_decode($compiler_output, true);

if ($errorData && isset($errorData['error'])) {
    // JqhtmlException extends ViewException, so this will show
    // the template file as the error source in Laravel/Ignition
    $exception = JqhtmlException::createFromJsError(
        $errorData['error'],
        $templatePath // Pass the actual template path
    );
    throw $exception;
}

// Alternative: Direct ViewException usage (if not using the bridge)
if ($errorData && isset($errorData['error'])) {
    $error = $errorData['error'];
    $line = $error['line'] ?? 1;

    throw new \Illuminate\View\ViewException(
        $error['message'],
        0,                    // code
        1,                    // severity
        $templatePath,        // file
        $line,               // line
        null                 // previous
    );
}
```

### Source Map Support

The package supports source maps for mapping compiled JavaScript back to original JQHTML templates:

```php
// Configure source map storage location
config(['jqhtml.source_maps_path' => storage_path('sourcemaps')]);

// The formatter will automatically look for source maps
// when an error includes a compiled file reference
$exception->setCompiledFile('/path/to/compiled.js');
```

### Custom Error Views

Create a custom error view at `resources/views/jqhtml/error.blade.php`:

```blade
@extends('errors::layout')

@section('title', 'JQHTML Template Error')

@section('message')
    <div class="jqhtml-error">
        <h2>{{ $exception->getMessage() }}</h2>

        @if($exception->getSuggestion())
            <div class="suggestion">
                💡 {{ $exception->getSuggestion() }}
            </div>
        @endif

        @if($exception->getTemplateFile())
            <div class="location">
                📍 {{ $exception->getTemplateFile() }}:{{ $exception->getTemplateLine() }}:{{ $exception->getTemplateColumn() }}
            </div>
        @endif

        @if(isset($error_data['source_context']))
            <div class="source-context">
                <pre><code>@foreach($error_data['source_context'] as $line)
@if($line['is_error_line'])<strong>> {{ $line['line_number'] }} | {{ $line['content'] }}
@if($line['error_column']){{ str_repeat(' ', $line['error_column'] + 8) }}{{ str_repeat('^', 20) }}@endif</strong>
@else  {{ $line['line_number'] }} | {{ $line['content'] }}
@endif
@endforeach</code></pre>
            </div>
        @endif
    </div>
@endsection
```

## Configuration Options

```php
return [
    // Where to store source map files
    'source_maps_path' => storage_path('jqhtml-sourcemaps'),

    // Show source code context in errors
    'show_source_context' => env('APP_DEBUG', false),

    // Lines of context to show
    'context_lines' => 5,

    // Cache compiled templates
    'cache_compiled' => !env('APP_DEBUG', false),

    // Where to store compiled templates
    'compiled_path' => storage_path('jqhtml-compiled'),

    // Enable source map generation
    'enable_source_maps' => env('APP_DEBUG', false),

    // Source map mode: 'inline', 'external', or 'both'
    'source_map_mode' => 'external',
];
```

## Laravel Ignition Integration

The package automatically integrates with Laravel Ignition (if installed) to provide enhanced error display:

- Template file location with line and column numbers
- Source code context with error highlighting
- Helpful suggestions for common mistakes
- Source map resolution for compiled files

## API Reference

### JqhtmlException

Main exception class for JQHTML template errors. **Extends Laravel's `ViewException`** for optimal integration with Laravel's error handling and Ignition error pages.

When thrown, this exception ensures:
- The template file appears as the error source in Laravel/Ignition
- Line numbers point to the actual template location
- The error page shows your JQHTML template, not the PHP processor

### JqhtmlErrorFormatter

Formats exceptions for display with source context and source map support.

### JqhtmlExceptionRenderer

Renders exceptions for web and JSON responses.

### JqhtmlServiceProvider

Registers services and extends Laravel's exception handler.

### JqhtmlErrorMiddleware

Middleware for catching and wrapping JQHTML errors.

## License

MIT