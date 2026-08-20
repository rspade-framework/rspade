<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

// The RSX dispatch system handles all RSX routes through RsxServiceProvider
// No additional routes needed here
// Compiled bundle assets are served via AssetHandler in the RSX dispatch system

// Test route for Bundle Facade and Blade directives
Route::get('/test-bundle-facade', function() {
    return view('test-bundle-facade');
});

// All RSX routes are handled through the 404 exception handler
// This allows Laravel routes to have priority

// Note: IDE Helper endpoints have been migrated to /_ide/service/* (standalone handler.php)
// This provides better performance by bypassing Laravel for IDE integration requests