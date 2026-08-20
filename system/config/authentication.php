<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Verification Methods
    |--------------------------------------------------------------------------
    |
    | This file configures the various authentication verification methods
    | used throughout the application. These options determine how users
    | verify their identity in different contexts.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication
    |--------------------------------------------------------------------------
    |
    | Configure two-factor authentication (2FA) settings for the application.
    | This includes SMS verification, email verification, and related options.
    |
    */
    
    'two_factor' => [
        /*
        |--------------------------------------------------------------------------
        | Enable Two-Factor Authentication
        |--------------------------------------------------------------------------
        |
        | When set to true, two-factor authentication features will be active.
        | When disabled, all 2FA features are bypassed.
        |
        */
        'enabled' => env('TWO_FACTOR_ENABLED', false),
        
        /*
        |--------------------------------------------------------------------------
        | Unrecognized Browser Verification Method
        |--------------------------------------------------------------------------
        |
        | Method to use when verifying logins from unrecognized browsers.
        | Options: 'sms', 'email', 'sms_email_fallback', 'none'
        |
        | - 'sms': Use SMS verification only
        | - 'email': Use email verification only
        | - 'sms_email_fallback': Try SMS first, fall back to email if SMS not available
        | - 'none': No verification required
        |
        */
        'unrecognized_browser_method' => env('TWO_FACTOR_BROWSER_METHOD', 'none'),
        
        /*
        |--------------------------------------------------------------------------
        | New Account Verification Method
        |--------------------------------------------------------------------------
        |
        | Method to use when verifying new account registrations.
        | Options: 'sms', 'email', 'sms_email_fallback', 'none'
        |
        */
        'new_account_method' => env('TWO_FACTOR_ACCOUNT_METHOD', 'email'),
        
        /*
        |--------------------------------------------------------------------------
        | Password Reset Verification Method
        |--------------------------------------------------------------------------
        |
        | Method to use when verifying password reset requests.
        | Options: 'sms', 'email', 'sms_email_fallback', 'none'
        |
        */
        'password_reset_method' => env('TWO_FACTOR_RESET_METHOD', 'email'),
        
        /*
        |--------------------------------------------------------------------------
        | SMS Authentication Settings
        |--------------------------------------------------------------------------
        |
        */
        'sms' => [
            // Allow SMS authentication (separate from verification)
            'allow_sms_login' => env('SMS_LOGIN_ENABLED', false),
            
            // Code validity in minutes
            'code_lifetime' => env('SMS_CODE_LIFETIME', 10),
            
            // Code length (number of digits)
            'code_length' => 6,
            
            // Resend timeout in seconds (e.g., 30 minutes = 1800 seconds)
            'resend_timeout' => env('SMS_RESEND_TIMEOUT', 1800),
            
            // Format of SMS message
            'message_format' => 'Your verification code is: {code}',
        ],

        /*
        |--------------------------------------------------------------------------
        | Trusted Device Settings
        |--------------------------------------------------------------------------
        |
        */
        'trusted_devices' => [
            // How long a device is trusted before requiring re-verification (in days)
            'lifetime' => env('TRUSTED_DEVICE_LIFETIME', 30),
            
            // Cookie name for the trusted device
            'cookie_name' => 'trusted_device',
            
            // How many devices can be trusted per user (0 for unlimited)
            'max_devices' => env('MAX_TRUSTED_DEVICES', 5),
        ],

        /*
        |--------------------------------------------------------------------------
        | Email Verification Settings
        |--------------------------------------------------------------------------
        |
        */
        'email' => [
            // How long a verification link is valid (in minutes)
            'verification_lifetime' => env('EMAIL_VERIFICATION_LIFETIME', 1440), // 24 hours
            
            // How long an invitation link is valid (in days)
            'invitation_lifetime' => env('EMAIL_INVITATION_LIFETIME', 7),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Single Sign-On (SSO) Configuration
    |--------------------------------------------------------------------------
    |
    | Configure third-party authentication providers like Google, Microsoft,
    | and Facebook for single sign-on capabilities.
    |
    */
    'sso' => [
        /*
        |--------------------------------------------------------------------------
        | Enable SSO
        |--------------------------------------------------------------------------
        |
        | Master switch to enable/disable all SSO functionality
        |
        */
        'enabled' => env('SSO_ENABLED', false),
        
        /*
        |--------------------------------------------------------------------------
        | Providers Configuration
        |--------------------------------------------------------------------------
        |
        | Configure each SSO provider individually
        |
        */
        'providers' => [
            'google' => [
                'enabled' => env('SSO_GOOGLE_ENABLED', false),
                'client_id' => env('SSO_GOOGLE_CLIENT_ID'),
                'client_secret' => env('SSO_GOOGLE_CLIENT_SECRET'),
                'redirect' => env('APP_URL') . '/auth/google/callback',
                'label' => 'Google',
                'icon' => 'fab fa-google',
                // Get credentials from: https://console.developers.google.com/
            ],
            
            'microsoft' => [
                'enabled' => env('SSO_MICROSOFT_ENABLED', false),
                'client_id' => env('SSO_MICROSOFT_CLIENT_ID'),
                'client_secret' => env('SSO_MICROSOFT_CLIENT_SECRET'),
                'tenant' => env('SSO_MICROSOFT_TENANT', 'common'),
                'redirect' => env('APP_URL') . '/auth/microsoft/callback',
                'label' => 'Microsoft',
                'icon' => 'fab fa-microsoft',
                // Get credentials from: https://portal.azure.com/ under App Registrations
            ],
            
            'facebook' => [
                'enabled' => env('SSO_FACEBOOK_ENABLED', false),
                'client_id' => env('SSO_FACEBOOK_CLIENT_ID'),
                'client_secret' => env('SSO_FACEBOOK_CLIENT_SECRET'),
                'redirect' => env('APP_URL') . '/auth/facebook/callback',
                'label' => 'Facebook',
                'icon' => 'fab fa-facebook',
                // Get credentials from: https://developers.facebook.com/apps/
            ],
        ],
        
        /*
        |--------------------------------------------------------------------------
        | SSO Link Options
        |--------------------------------------------------------------------------
        |
        | Configure behavior for linking SSO accounts to existing user accounts
        |
        */
        'account_linking' => [
            // If true, users can link multiple SSO providers to one account
            'allow_multiple_providers' => true,
            
            // If true, users must verify email before linking an SSO account
            'require_email_verification' => true,
            
            // If true and email matches, automatically link to existing account
            'auto_link_by_email' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pending Registration Configuration
    |--------------------------------------------------------------------------
    |
    | Configure settings for pending registrations that require verification
    |
    */
    'pending_registrations' => [
        // How long a pending registration is stored before expiring (in hours)
        'expiration_hours' => env('PENDING_REGISTRATION_EXPIRATION', 24),
        
        // Whether to allow re-registration with the same email before verification
        'allow_reregistration' => true,
    ],
];