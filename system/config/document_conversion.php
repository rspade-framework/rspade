<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Document Conversion Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration for document conversion services.
    | It controls which types of conversions are enabled and how they are
    | executed.
    |
    */
    
    /*
    |--------------------------------------------------------------------------
    | Enable Document Conversion
    |--------------------------------------------------------------------------
    |
    | Master switch to enable/disable all document conversion functionality.
    | When disabled, all conversion attempts will be skipped.
    |
    */
    'enabled' => env('DOCUMENT_CONVERSION_ENABLED', true),
    
    /*
    |--------------------------------------------------------------------------
    | Conversion Types
    |--------------------------------------------------------------------------
    |
    | Configure which types of conversions are enabled. Each conversion type
    | can be individually enabled or disabled.
    |
    */
    'conversions' => [
        // PDF to thumbnail conversion
        'pdf_thumbnail' => [
            'enabled' => env('PDF_THUMBNAIL_ENABLED', true),
            'method' => env('PDF_THUMBNAIL_METHOD', 'imagick'), // 'imagick', 'ghostscript', 'remote'
            'resolution' => env('PDF_THUMBNAIL_RESOLUTION', 300),
        ],
        
        // Office documents to thumbnail conversion
        'office_thumbnail' => [
            'enabled' => env('OFFICE_THUMBNAIL_ENABLED', true),
            'method' => env('OFFICE_THUMBNAIL_METHOD', 'libreoffice'), // 'libreoffice', 'remote'
        ],
        
        // Spreadsheet to CSV conversion
        'spreadsheet_to_csv' => [
            'enabled' => env('SPREADSHEET_TO_CSV_ENABLED', true),
            'method' => env('SPREADSHEET_TO_CSV_METHOD', 'libreoffice'), // 'libreoffice', 'remote'
        ],
        
        // Document to text extraction for fulltext search
        'text_extraction' => [
            'enabled' => env('TEXT_EXTRACTION_ENABLED', true),
            'store_extracted_text' => env('STORE_EXTRACTED_TEXT', true),
            'method' => env('TEXT_EXTRACTION_METHOD', 'local'), // 'local', 'tika', 'remote'
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Supported File Types
    |--------------------------------------------------------------------------
    |
    | Define which file extensions are supported for each conversion type.
    |
    */
    'supported_extensions' => [
        'pdf_thumbnail' => ['pdf'],
        
        'office_thumbnail' => [
            'doc', 'docx', 'odt', 'rtf', 'txt',
            'ppt', 'pptx', 'odp',
            'xls', 'xlsx', 'ods',
        ],
        
        'spreadsheet_to_csv' => [
            'xls', 'xlsx', 'ods', 'csv'
        ],
        
        'text_extraction' => [
            'pdf', 'doc', 'docx', 'odt', 'rtf', 'txt',
            'ppt', 'pptx', 'odp',
            'xls', 'xlsx', 'ods', 'csv',
            'html', 'htm', 'xml',
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | External Service Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for external document conversion services.
    |
    */
    'services' => [
        // LibreOffice configuration
        'libreoffice' => [
            'binary_path' => env('LIBREOFFICE_PATH', '/usr/bin/soffice'),
            'timeout' => env('LIBREOFFICE_TIMEOUT', 60), // in seconds
        ],
        
        // Ghostscript configuration
        'ghostscript' => [
            'binary_path' => env('GHOSTSCRIPT_PATH', '/usr/bin/gs'),
            'timeout' => env('GHOSTSCRIPT_TIMEOUT', 60), // in seconds
        ],
        
        // Apache Tika configuration for text extraction
        'tika' => [
            'jar_path' => env('TIKA_PATH', '/usr/local/bin/tika-app.jar'),
            'timeout' => env('TIKA_TIMEOUT', 60), // in seconds
        ],
        
        // Remote conversion service (API)
        'remote' => [
            'api_url' => env('DOCUMENT_CONVERSION_API_URL'),
            'api_key' => env('DOCUMENT_CONVERSION_API_KEY'),
            'timeout' => env('DOCUMENT_CONVERSION_API_TIMEOUT', 30), // in seconds
        ],
        
        // Docker service for isolated conversion
        'docker' => [
            'enabled' => env('DOCKER_CONVERSION_ENABLED', false),
            'image' => env('DOCKER_CONVERSION_IMAGE', 'libreoffice/online:latest'),
            'timeout' => env('DOCKER_CONVERSION_TIMEOUT', 120), // in seconds
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Temporary Files
    |--------------------------------------------------------------------------
    |
    | Configuration for temporary files used during conversion.
    |
    */
    'temp_directory' => env('DOCUMENT_CONVERSION_TEMP_DIR', storage_path('app/temp')),
    'cleanup_temp_files' => env('DOCUMENT_CONVERSION_CLEANUP_TEMP', true),
    
    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for document conversion queuing system.
    |
    */
    'queue' => [
        'enabled' => env('DOCUMENT_CONVERSION_QUEUE_ENABLED', true),
        'queue_name' => env('DOCUMENT_CONVERSION_QUEUE', 'document-conversion'),
        'connection' => env('DOCUMENT_CONVERSION_QUEUE_CONNECTION', 'sync'),
        'retry_after' => env('DOCUMENT_CONVERSION_RETRY_AFTER', 60), // in seconds
        'max_tries' => env('DOCUMENT_CONVERSION_MAX_TRIES', 3),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Content Storage
    |--------------------------------------------------------------------------
    |
    | Configuration for storing extracted content.
    |
    */
    'content_storage' => [
        'disk' => env('DOCUMENT_CONTENT_DISK', 'local'),
        'directory' => env('DOCUMENT_CONTENT_DIRECTORY', 'document-content'),
        'suffix' => env('DOCUMENT_CONTENT_SUFFIX', '.content.txt'),
    ],
];