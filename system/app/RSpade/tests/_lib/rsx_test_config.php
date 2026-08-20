<?php
/**
 * RSpade Test Configuration
 *
 * Additional configuration loaded when RSX_ADDITIONAL_CONFIG env variable is set.
 * Used by test runner to include tests directory in manifest scanning.
 *
 * Loaded by: test_mode_enter.sh
 * Cleared by: test_mode_exit.sh
 */

return [
    'manifest' => [
        'scan_directories' => [
            'app/RSpade/tests',  // Include framework tests directory in manifest
        ],
    ],
];
