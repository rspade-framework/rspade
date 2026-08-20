#!/usr/bin/env node

/**
 * CSS URL Inliner
 *
 * Resolves relative url() references in CSS to absolute CDN URLs,
 * downloads the assets, and inlines them as base64 data URIs.
 *
 * Usage: node inline-css-urls.js <base-url> <input-file> <output-file>
 *
 * - base-url: The CDN URL the CSS was downloaded from (for resolving relative paths)
 * - input-file: Path to the downloaded CSS file
 * - output-file: Path to write the processed CSS with inlined assets
 *
 * Exit codes:
 * - 0: Success
 * - 1: Error (failed to download asset, invalid arguments, etc.)
 */

const fs = require('fs');
const path = require('path');
const https = require('https');
const http = require('http');
const postcss = require('postcss');
const postcssUrl = require('postcss-url');

// Parse arguments
const args = process.argv.slice(2);
if (args.length !== 3) {
    console.error('Usage: node inline-css-urls.js <base-url> <input-file> <output-file>');
    process.exit(1);
}

const [baseUrl, inputFile, outputFile] = args;

// Track failed downloads to report all at once
const failedUrls = [];

/**
 * Get MIME type from file extension
 */
function getMimeType(url) {
    const ext = path.extname(url).toLowerCase().split('?')[0]; // Remove query string
    const mimeTypes = {
        '.woff2': 'font/woff2',
        '.woff': 'font/woff',
        '.ttf': 'font/ttf',
        '.otf': 'font/otf',
        '.eot': 'application/vnd.ms-fontobject',
        '.svg': 'image/svg+xml',
        '.png': 'image/png',
        '.jpg': 'image/jpeg',
        '.jpeg': 'image/jpeg',
        '.gif': 'image/gif',
        '.webp': 'image/webp',
        '.ico': 'image/x-icon',
    };
    return mimeTypes[ext] || 'application/octet-stream';
}

/**
 * Resolve a relative URL against a base URL
 */
function resolveUrl(relativeUrl, baseUrl) {
    // Already absolute
    if (relativeUrl.startsWith('http://') || relativeUrl.startsWith('https://')) {
        return relativeUrl;
    }

    // Data URI - already inline
    if (relativeUrl.startsWith('data:')) {
        return null; // Skip, already inline
    }

    // Parse base URL
    const base = new URL(baseUrl);

    // Handle different relative path formats
    if (relativeUrl.startsWith('//')) {
        // Protocol-relative
        return base.protocol + relativeUrl;
    } else if (relativeUrl.startsWith('/')) {
        // Absolute path
        return base.origin + relativeUrl;
    } else {
        // Relative path - resolve against base directory
        const baseDir = base.href.substring(0, base.href.lastIndexOf('/') + 1);
        return new URL(relativeUrl, baseDir).href;
    }
}

/**
 * Download a URL and return as Buffer
 */
function downloadUrl(url) {
    return new Promise((resolve, reject) => {
        const protocol = url.startsWith('https://') ? https : http;

        const request = protocol.get(url, {
            headers: {
                'User-Agent': 'RSpade/1.0',
                'Accept': '*/*',
            },
            timeout: 30000,
        }, (response) => {
            // Handle redirects
            if (response.statusCode >= 300 && response.statusCode < 400 && response.headers.location) {
                downloadUrl(response.headers.location).then(resolve).catch(reject);
                return;
            }

            if (response.statusCode !== 200) {
                reject(new Error(`HTTP ${response.statusCode}`));
                return;
            }

            const chunks = [];
            response.on('data', chunk => chunks.push(chunk));
            response.on('end', () => resolve(Buffer.concat(chunks)));
            response.on('error', reject);
        });

        request.on('error', reject);
        request.on('timeout', () => {
            request.destroy();
            reject(new Error('Request timeout'));
        });
    });
}

/**
 * Process CSS and inline all url() references
 */
async function processCSS() {
    // Read input CSS
    if (!fs.existsSync(inputFile)) {
        console.error(`Error: Input file not found: ${inputFile}`);
        process.exit(1);
    }

    const css = fs.readFileSync(inputFile, 'utf-8');

    // Cache for downloaded assets (avoid re-downloading same URL)
    const assetCache = new Map();

    // Process with PostCSS
    const result = await postcss()
        .use(postcssUrl({
            url: async (asset) => {
                const originalUrl = asset.url;

                // Skip data URIs
                if (originalUrl.startsWith('data:')) {
                    return originalUrl;
                }

                // Resolve to absolute URL
                const absoluteUrl = resolveUrl(originalUrl, baseUrl);
                if (!absoluteUrl) {
                    return originalUrl; // Skip if couldn't resolve
                }

                // Check cache
                if (assetCache.has(absoluteUrl)) {
                    return assetCache.get(absoluteUrl);
                }

                // Download and convert to data URI
                try {
                    console.log(`  Downloading: ${absoluteUrl}`);
                    const buffer = await downloadUrl(absoluteUrl);
                    const mimeType = getMimeType(absoluteUrl);
                    const base64 = buffer.toString('base64');
                    const dataUri = `data:${mimeType};base64,${base64}`;

                    // Cache for reuse
                    assetCache.set(absoluteUrl, dataUri);

                    console.log(`    OK: ${buffer.length} bytes -> ${base64.length} chars base64`);
                    return dataUri;
                } catch (err) {
                    console.error(`  FAILED: ${absoluteUrl} - ${err.message}`);
                    failedUrls.push({ url: absoluteUrl, error: err.message });
                    return originalUrl; // Keep original URL (will fail at runtime)
                }
            }
        }))
        .process(css, { from: inputFile, to: outputFile });

    // Check for failures
    if (failedUrls.length > 0) {
        console.error('\nFATAL: Failed to download the following URLs:');
        for (const { url, error } of failedUrls) {
            console.error(`  - ${url}: ${error}`);
        }
        process.exit(1);
    }

    // Write output
    const outputDir = path.dirname(outputFile);
    if (!fs.existsSync(outputDir)) {
        fs.mkdirSync(outputDir, { recursive: true });
    }

    fs.writeFileSync(outputFile, result.css, 'utf-8');

    console.log(`\nSuccess: Processed CSS written to ${outputFile}`);
    console.log(`  Assets inlined: ${assetCache.size}`);
}

// Run
processCSS().catch(err => {
    console.error(`Error: ${err.message}`);
    process.exit(1);
});
