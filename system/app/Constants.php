<?php

namespace App;

/**
 * Application-wide constants
 * 
 * This file contains static resources and constants that are used throughout the application.
 * 
 * Purpose:
 * - Centralizes commonly used resources like state lists, MIME types, etc.
 * - Provides a single source of truth for these constants
 * - Makes maintenance easier - edit once, use everywhere
 * - Improves consistency across the application
 * 
 * Usage:
 * - Include this file where constants are needed using: use App\Constants;
 * - Access constants via the class constants (e.g., Constants::STATES)
 * 
 * Guidelines:
 * - Keep this file as a catchall for non-application-specific constants
 * - Group related constants in meaningful arrays/classes
 * - Add clear comments for each constant group
 * - Use UPPERCASE for constant names
 * - For long lists, maintain alphabetical order where appropriate
 * 
 * Examples of appropriate constants:
 * - US States and territories
 * - Country codes
 * - MIME types
 * - Common date/time formats
 * - Standard measurement units
 * - Common regular expressions (email, phone, etc.)
 */
class Constants
{
    // User ID for CLI operations
    const CLI_USER = 'cli';
    
    // System user email
    const SYSTEM_USER_EMAIL = 'system@rspade.local';
    
    // System user access level
    const SYSTEM_USER_ACCESS_LEVEL = 90;
    /**
     * US States and territories with abbreviations
     * Array of state abbreviations to full state names, in alphabetical order
     */
    public const STATES = [
        'AL' => 'Alabama',
        'AK' => 'Alaska',
        'AZ' => 'Arizona',
        'AR' => 'Arkansas',
        'CA' => 'California',
        'CO' => 'Colorado',
        'CT' => 'Connecticut',
        'DE' => 'Delaware',
        'DC' => 'District of Columbia',
        'FL' => 'Florida',
        'GA' => 'Georgia',
        'HI' => 'Hawaii',
        'ID' => 'Idaho',
        'IL' => 'Illinois',
        'IN' => 'Indiana',
        'IA' => 'Iowa',
        'KS' => 'Kansas',
        'KY' => 'Kentucky',
        'LA' => 'Louisiana',
        'ME' => 'Maine',
        'MD' => 'Maryland',
        'MA' => 'Massachusetts',
        'MI' => 'Michigan',
        'MN' => 'Minnesota',
        'MS' => 'Mississippi',
        'MO' => 'Missouri',
        'MT' => 'Montana',
        'NE' => 'Nebraska',
        'NV' => 'Nevada',
        'NH' => 'New Hampshire',
        'NJ' => 'New Jersey',
        'NM' => 'New Mexico',
        'NY' => 'New York',
        'NC' => 'North Carolina',
        'ND' => 'North Dakota',
        'OH' => 'Ohio',
        'OK' => 'Oklahoma',
        'OR' => 'Oregon',
        'PA' => 'Pennsylvania',
        'RI' => 'Rhode Island',
        'SC' => 'South Carolina',
        'SD' => 'South Dakota',
        'TN' => 'Tennessee',
        'TX' => 'Texas',
        'UT' => 'Utah',
        'VT' => 'Vermont',
        'VA' => 'Virginia',
        'WA' => 'Washington',
        'WV' => 'West Virginia',
        'WI' => 'Wisconsin',
        'WY' => 'Wyoming'
    ];

    /**
     * Common date formats
     */
    public const DATE_FORMATS = [
        'SHORT' => 'm/d/Y',
        'MEDIUM' => 'M j, Y',
        'LONG' => 'F j, Y',
        'TIME' => 'g:i A',
        'DATETIME' => 'M j, Y g:i A'
    ];
    
    /**
     * Generic operation/action verbs used in CRUD patterns
     *
     * These are common verbs developers use for actions performed ON modules/features.
     * Used by code quality rules to detect when renaming files would create
     * proliferation of identical generic filenames (e.g., many "edit_action.js" files).
     */
    public const GENERIC_OPERATIONS = [
        // Core CRUD
        'create', 'read', 'update', 'delete',
        'add', 'view', 'edit', 'remove',
        'new', 'show', 'modify', 'destroy',

        // List/Browse
        'list', 'index', 'browse', 'search', 'find', 'filter', 'query',

        // Data retrieval
        'get', 'fetch', 'load', 'retrieve', 'pull', 'lookup',

        // Data persistence
        'save', 'store', 'persist', 'write', 'put', 'insert',
        'post', 'submit', 'send', 'push',

        // File operations
        'upload', 'download', 'import', 'export', 'attach',

        // Sync/Refresh
        'sync', 'refresh', 'reload', 'reset',

        // Detail views
        'details', 'detail', 'info', 'summary', 'overview', 'single', 'item',

        // Form operations
        'form', 'input', 'enter', 'wizard', 'step',

        // Batch operations
        'bulk', 'batch', 'mass', 'multi', 'all',

        // State changes
        'archive', 'restore', 'recover', 'trash',
        'activate', 'deactivate', 'enable', 'disable',
        'publish', 'unpublish', 'draft',
        'approve', 'reject', 'review', 'pending',
        'lock', 'unlock', 'freeze',
        'open', 'close', 'complete', 'finish',
        'start', 'stop', 'pause', 'resume', 'cancel',

        // Auth actions
        'login', 'logout', 'signin', 'signout', 'auth',
        'register', 'signup', 'join',
        'invite', 'accept', 'confirm', 'verify',

        // Processing
        'process', 'handle', 'execute', 'run',
        'convert', 'transform', 'parse', 'generate',
        'check', 'test', 'validate',

        // Communication actions
        'notify', 'alert', 'email', 'message',

        // Selection/Movement
        'select', 'pick', 'choose', 'assign', 'move', 'copy', 'clone',

        // Output
        'preview', 'print', 'report',
    ];

    // Additional constants can be added here as needed:
    // const MIME_TYPES = [...];
    // const COUNTRY_CODES = [...];
    // etc.
}