<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notification Channel Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration options for the application's notification
    | system, including settings for email, SMS, and in-app notifications.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | SMS Notifications
    |--------------------------------------------------------------------------
    |
    | Configure SMS notification settings, including consent requirements,
    | sending behavior, and queue management.
    |
    */
    'sms' => [
        /*
        |--------------------------------------------------------------------------
        | Enable SMS for Application Notifications
        |--------------------------------------------------------------------------
        |
        | This setting determines if the application uses SMS for general notifications
        | beyond authentication purposes. When set to true, users will see consent
        | options during registration and in their profile settings.
        |
        | This is separate from SMS authentication - this is for regular app notifications.
        |
        */
        'application_notifications_enabled' => env('SMS_APP_NOTIFICATIONS_ENABLED', false),
        
        /*
        |--------------------------------------------------------------------------
        | SMS Queue Settings
        |--------------------------------------------------------------------------
        |
        | Configuration for the SMS queue system that handles sending and retrying SMS
        | messages.
        |
        */
        'queue' => [
            // Retry delay in seconds before attempting to resend a failed SMS
            'retry_delay' => env('SMS_RETRY_DELAY', 30),
            
            // Maximum time in minutes to keep retrying a message before marking as failed
            'message_timeout' => env('SMS_MESSAGE_TIMEOUT', 5),
            
            // Maximum number of retry attempts before marking as failed
            'max_retries' => env('SMS_MAX_RETRIES', 3),
        ],
        
        /*
        |--------------------------------------------------------------------------
        | Phone Number Validation
        |--------------------------------------------------------------------------
        |
        | Settings for phone number validation and formatting.
        |
        */
        'phone' => [
            // Blocks test numbers with 555 in North American numbers
            'block_test_numbers' => true,
            
            // Default country code for phone numbers without one
            'default_country_code' => env('DEFAULT_COUNTRY_CODE', 'US'),
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Registration Field Requirements
    |--------------------------------------------------------------------------
    |
    | Configure which fields are required or optional during user registration.
    | These settings determine what information is collected during signup.
    |
    */
    'registration' => [
        // Require phone number during registration
        'require_phone' => env('REGISTRATION_REQUIRE_PHONE', false),
        
        // Collect phone number optionally during registration (ignored if require_phone is true)
        'collect_phone' => env('REGISTRATION_COLLECT_PHONE', false),
        
        // Require physical address during registration
        'require_address' => env('REGISTRATION_REQUIRE_ADDRESS', false),
        
        // Collect physical address optionally during registration (ignored if require_address is true)
        'collect_address' => env('REGISTRATION_COLLECT_ADDRESS', false),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | General Notification Settings
    |--------------------------------------------------------------------------
    |
    | General settings that apply to all notification types.
    |
    */
    'channels' => [
        // Enable in-app notifications
        'in_app' => env('NOTIFICATIONS_IN_APP_ENABLED', true),
        
        // Enable email notifications
        'email' => env('NOTIFICATIONS_EMAIL_ENABLED', true),
        
        // Enable SMS notifications (requires SMS service to be configured)
        'sms' => env('NOTIFICATIONS_SMS_ENABLED', false),
    ],
];