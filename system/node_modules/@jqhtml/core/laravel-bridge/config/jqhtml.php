<?php

return [
    /*
    |--------------------------------------------------------------------------
    | JQHTML Source Maps Path
    |--------------------------------------------------------------------------
    |
    | This option defines where JQHTML source map files should be stored.
    | Source maps help map compiled JavaScript back to original JQHTML templates
    | for better error reporting and debugging.
    |
    */
    'source_maps_path' => storage_path('jqhtml-sourcemaps'),

    /*
    |--------------------------------------------------------------------------
    | Show Source Context
    |--------------------------------------------------------------------------
    |
    | When enabled, error messages will include the surrounding source code
    | context to help identify the exact location and nature of the error.
    |
    */
    'show_source_context' => env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Error Context Lines
    |--------------------------------------------------------------------------
    |
    | Number of lines to show before and after the error line when displaying
    | source context in error messages.
    |
    */
    'context_lines' => 5,

    /*
    |--------------------------------------------------------------------------
    | Cache Compiled Templates
    |--------------------------------------------------------------------------
    |
    | When enabled, compiled JQHTML templates will be cached to improve
    | performance. Disable during development for immediate template updates.
    |
    */
    'cache_compiled' => env('JQHTML_CACHE', !env('APP_DEBUG', false)),

    /*
    |--------------------------------------------------------------------------
    | Compiled Templates Path
    |--------------------------------------------------------------------------
    |
    | Directory where compiled JQHTML templates should be stored.
    |
    */
    'compiled_path' => storage_path('jqhtml-compiled'),

    /*
    |--------------------------------------------------------------------------
    | Enable Source Maps
    |--------------------------------------------------------------------------
    |
    | Whether to generate source maps for compiled templates. Source maps
    | increase compilation time slightly but provide better debugging.
    |
    */
    'enable_source_maps' => env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Source Map Mode
    |--------------------------------------------------------------------------
    |
    | How source maps should be generated:
    | - 'inline': Embed source map directly in compiled file
    | - 'external': Save source map as separate .map file
    | - 'both': Generate both inline and external source maps
    |
    */
    'source_map_mode' => 'external',
];