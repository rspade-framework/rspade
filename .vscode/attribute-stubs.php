<?php

/**
 * RSX Attribute Stubs for IDE IntelliSense
 *
 * This file provides stub definitions for RSX attributes that are read via reflection only.
 * These are NOT actual class definitions - they exist purely for IDE autocompletion.
 * The framework reads these attributes dynamically; no actual class files are loaded.
 */

namespace {

/**
 * Static Page Cache Attribute
 *
 * Marks a route method for server-side rendering (SSR) and full page caching (FPC).
 * Routes with this attribute will be pre-rendered as static HTML for unauthenticated users.
 *
 * Usage:
 * ```php
 * #[Static_Page]
 * #[Route('/about')]
 * public static function about(Request $request, array $params = []) {
 *     // Route implementation
 * }
 * ```
 *
 * Requirements:
 * - Route must be GET only (no POST, PUT, DELETE)
 * - Only served to unauthenticated users (no active session)
 * - Cache auto-invalidates on deployment (build_key changes)
 *
 * Cache behavior:
 * - Generates static HTML via headless Chrome (Playwright)
 * - Stores in Redis: ssr_fpc:{build_key}:{url_hash}
 * - Supports ETags for 304 Not Modified responses
 * - Cache headers: 0s in dev, 5min in prod
 *
 * Generate cache:
 * ```bash
 * php artisan rsx:ssr_fpc:create /about
 * ```
 *
 * Clear all caches:
 * ```bash
 * php artisan rsx:ssr_fpc:reset
 * ```
 */
#[Attribute]
class Static_Page
{
    public function __construct()
    {
    }
}

}
