<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sitemap Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration options for the sitemap generator.
    |
    */

    // Path where the sitemap will be generated (relative to public path)
    'path' => 'sitemap.xml',
    
    // How frequently the sitemap should be regenerated (in hours)
    'regenerate_interval' => 3,
    
    // Maximum number of URLs per sitemap (0 for unlimited)
    'max_urls' => 50000,
    
    // Whether to use sitemap index for large sites (creates multiple sitemaps)
    'use_index' => false,
    
    // Default values for sitemap entries
    'defaults' => [
        'change_freq' => 'weekly',
        'priority' => 0.5,
    ],
    
    // Which parts of the application should trigger regeneration
    'observe' => [
        'blog_posts' => true,
        'static_blocks' => true,
        'todo_lists' => true,
    ],
    
    // Cooldown period after generation (in seconds) to prevent multiple regenerations
    'cooldown' => 30,
    
    // Categories for organizing sitemap entries
    'categories' => [
        'pages' => [
            'name' => 'Pages',
            'description' => 'Static pages and content',
            'priority' => 0.8,
            'change_freq' => 'monthly',
        ],
        'blog' => [
            'name' => 'Blog',
            'description' => 'Blog posts and categories',
            'priority' => 0.7,
            'change_freq' => 'weekly',
        ],
        'user_content' => [
            'name' => 'User Content',
            'description' => 'User-generated content like public todo lists',
            'priority' => 0.6,
            'change_freq' => 'daily',
        ],
        'auth' => [
            'name' => 'Authentication',
            'description' => 'Login, registration, and account pages',
            'priority' => 0.4,
            'change_freq' => 'monthly',
        ],
    ],
    
    // Public site URL (used if app.url is not set)
    'site_url' => env('SITE_URL', null),
];