<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Twilio SMS
    |--------------------------------------------------------------------------
    |
    | Twilio is used for sending SMS messages, including verification codes.
    | Create an account at https://www.twilio.com/ to get these credentials.
    |
    */
    'twilio' => [
        'enabled' => env('TWILIO_ENABLED', false),
        'sid' => env('TWILIO_SID', ''),
        'token' => env('TWILIO_TOKEN', ''),
        'from' => env('TWILIO_FROM', ''),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Social Media Authentication Services
    |--------------------------------------------------------------------------
    |
    | These settings are used for SSO authentication with various providers.
    | They are separate from the SSO settings in authentication.php which
    | control which providers are enabled in the UI.
    |
    */
    'google' => [
        'client_id' => env('SSO_GOOGLE_CLIENT_ID'),
        'client_secret' => env('SSO_GOOGLE_CLIENT_SECRET'),
        'redirect' => env('APP_URL') . '/auth/google/callback',
    ],
    
    'microsoft' => [
        'client_id' => env('SSO_MICROSOFT_CLIENT_ID'),
        'client_secret' => env('SSO_MICROSOFT_CLIENT_SECRET'),
        'tenant' => env('SSO_MICROSOFT_TENANT', 'common'),
        'redirect' => env('APP_URL') . '/auth/microsoft/callback',
    ],
    
    'facebook' => [
        'client_id' => env('SSO_FACEBOOK_CLIENT_ID'),
        'client_secret' => env('SSO_FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('APP_URL') . '/auth/facebook/callback',
    ],
];
