<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Settings
    |--------------------------------------------------------------------------
    |
    | This file contains settings for the RSpade API system.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | API Authentication
    |--------------------------------------------------------------------------
    |
    | Configuration for API authentication.
    |
    */
    'token_prefix' => 'rspade_',
    'token_expiry' => 365, // Default token expiration in days
    
    /*
    |--------------------------------------------------------------------------
    | API Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Rate limits for API requests.
    |
    */
    'rate_limit' => [
        'enabled' => true,
        'max_requests_per_minute' => 60,
        'max_requests_per_hour' => 1000,
        'max_requests_per_day' => 10000,
    ],
    
    /*
    |--------------------------------------------------------------------------
    | API Response Settings
    |--------------------------------------------------------------------------
    |
    | Settings for API responses.
    |
    */
    'max_results_per_api_call' => env('API_MAX_RESULTS', 100),
    'default_results_per_api_call' => 20,
    
    /*
    |--------------------------------------------------------------------------
    | API Documentation Settings
    |--------------------------------------------------------------------------
    |
    | Settings for the API documentation system.
    |
    */
    'docs' => [
        'title' => 'RSpade API Documentation',
        'description' => 'Documentation for the RSpade API system.',
        'version' => '1.0.0',
        'enable_tester' => true,
        'enable_auto_token_generation' => true,
    ],
    
    /*
    |--------------------------------------------------------------------------
    | API Token Permissions
    |--------------------------------------------------------------------------
    |
    | Available permission sets for API tokens.
    |
    */
    'permissions' => [
        'read' => [
            'description' => 'Read-only access to API endpoints',
            'endpoints' => [
                'todo-lists.index',
                'todo-lists.show',
                'todos.index',
                'todos.show',
            ],
        ],
        'write' => [
            'description' => 'Read-write access to API endpoints',
            'endpoints' => [
                'todo-lists.index',
                'todo-lists.show',
                'todo-lists.store',
                'todo-lists.update',
                'todo-lists.destroy',
                'todos.index',
                'todos.show',
                'todos.store',
                'todos.update',
                'todos.destroy',
            ],
        ],
        'admin' => [
            'description' => 'Administrative access to API endpoints',
            'endpoints' => [
                'todo-lists.index',
                'todo-lists.show',
                'todo-lists.store',
                'todo-lists.update',
                'todo-lists.destroy',
                'todos.index',
                'todos.show',
                'todos.store',
                'todos.update',
                'todos.destroy',
                'users.index',
                'users.show',
            ],
        ],
        'root' => [
            'description' => 'Root access to API endpoints (all endpoints)',
            'endpoints' => [
                '*',
            ],
        ],
    ],
];