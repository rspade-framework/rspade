<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Multi-Tenant Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration options for the multi-tenant system.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Enable Multi-Tenancy
    |--------------------------------------------------------------------------
    |
    | When set to false, the application will operate in single-tenant mode.
    | All users will be assigned to site ID 1, and multi-site features will
    | be disabled in the UI and backend.
    |
    */
    'enabled' => env('MULTI_TENANT_ENABLED', true),
    
    /*
    |--------------------------------------------------------------------------
    | Enable Multi-User Mode
    |--------------------------------------------------------------------------
    |
    | When set to false, the application will operate in single-user mode.
    | All users will be admins of their own site and user management features
    | will be disabled in the UI. Each user creates exactly one site on registration.
    |
    */
    'enable_multi_user' => env('MULTI_USER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Single User Tenant Mode
    |--------------------------------------------------------------------------
    |
    | When set to true, each user will automatically get their own site
    | upon registration. This is useful for applications where each user
    | should have their own isolated environment.
    |
    */
    'single_user_tenant' => env('SINGLE_USER_TENANT', false),

    /*
    |--------------------------------------------------------------------------
    | Default Site ID
    |--------------------------------------------------------------------------
    |
    | The site ID to use when multi-tenancy is disabled.
    | This is also the site ID used when a user has no site context.
    |
    */
    'default_site_id' => env('DEFAULT_SITE_ID', 1),

    /*
    |--------------------------------------------------------------------------
    | Session Key
    |--------------------------------------------------------------------------
    |
    | The key used to store the current site ID in the session.
    |
    */
    'session_key' => 'current_site_id',

    /*
    |--------------------------------------------------------------------------
    | Invitation Expiry Time
    |--------------------------------------------------------------------------
    |
    | The number of hours until an invitation expires.
    |
    */
    'invitation_expiry_hours' => 48,

    /*
    |--------------------------------------------------------------------------
    | Root User Email
    |--------------------------------------------------------------------------
    |
    | The email of the root user who has access to all sites.
    | Leave empty to disable the root user feature.
    |
    */
    'root_user_email' => env('ROOT_USER_EMAIL', null),

    /*
    |--------------------------------------------------------------------------
    | Auto-join First Site
    |--------------------------------------------------------------------------
    |
    | When a user signs up and doesn't have any site invitation,
    | should they automatically be added to the first site?
    | Useful for simple deployments or testing.
    |
    */
    'auto_join_first_site' => env('AUTO_JOIN_FIRST_SITE', false),

    /*
    |--------------------------------------------------------------------------
    | Site Creation Allowed
    |--------------------------------------------------------------------------
    |
    | Controls whether users can create new sites.
    | When set to false, only root users can create new sites.
    |
    */
    'allow_site_creation' => env('ALLOW_SITE_CREATION', true),
    
    /*
    |--------------------------------------------------------------------------
    | Administrator Permissions
    |--------------------------------------------------------------------------
    |
    | Special permissions that can be granted to admin users when running in
    | single-tenant mode or with the extended admin permissions enabled.
    |
    */
    'admin_permissions' => [
        'reset_user_passwords' => env('ADMIN_CAN_RESET_PASSWORDS', false),
        'view_user_sessions' => env('ADMIN_CAN_VIEW_SESSIONS', false),
    ],
];