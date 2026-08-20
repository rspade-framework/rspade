<?php

return [
    // Where to store source maps
    'source_maps_path' => storage_path('jqhtml-sourcemaps'),

    // Show source code context in errors
    'show_source_context' => env('APP_DEBUG', false),

    // Lines of context around errors
    'context_lines' => 5,

    // Enable source map generation
    'enable_source_maps' => env('APP_DEBUG', false),

    // Source map mode: 'inline', 'external', or 'both'
    'source_map_mode' => 'external',
];