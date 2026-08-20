<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CMS Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration options for the CMS features.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Feature Accessibility Settings
    |--------------------------------------------------------------------------
    |
    | These settings control which user roles can access CMS features.
    | Options:
    | - 'disabled': Feature is completely disabled
    | - 'root_only': Only root users can access the feature
    | - 'admin_or_root': Site administrators and root users can access the feature
    |
    */
    'feature_access' => [
        'static_blocks' => env('CMS_STATIC_BLOCKS_ACCESS', 'root_only'),
        'blog' => env('CMS_BLOG_ACCESS', 'admin_or_root'),
        'pages' => env('CMS_PAGES_ACCESS', 'root_only'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Settings
    |--------------------------------------------------------------------------
    |
    | Configuration options for CMS content management.
    |
    */
    'content' => [
        // Maximum length for blog post content
        'max_blog_content_length' => 100000,
        
        // Maximum length for page content
        'max_page_content_length' => 100000,
        
        // Default options for new blog posts
        'default_blog_options' => [
            'allow_comments' => true,
            'show_author' => true,
        ],
        
        // Available blog categories
        'blog_categories' => [
            'news' => 'News',
            'updates' => 'Updates',
            'tutorials' => 'Tutorials',
            'events' => 'Events',
            'announcements' => 'Announcements',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Layout Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for how CMS content is displayed in the layout.
    |
    */
    'layout' => [
        // Number of blog posts to show per page
        'blog_posts_per_page' => 10,
        
        // Number of featured posts to show on home page
        'featured_posts_count' => 3,
        
        // Show blog post dates
        'show_blog_dates' => true,
        
        // Show blog post author
        'show_blog_author' => true,
    ],
];