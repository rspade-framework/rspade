#!/usr/bin/env node

/**
 * Minification RPC Server
 *
 * Handles JavaScript (Terser) and CSS (cssnano) minification for production builds.
 * Runs as a persistent server to avoid Node.js startup overhead for multiple files.
 *
 * Usage:
 *   Server mode: node minify-server.js --socket=/path/to/socket
 *
 * RPC Methods:
 *   - ping: Health check
 *   - minify: Minify JS or CSS content
 *   - shutdown: Graceful server termination
 */

const fs = require('fs');
const net = require('net');
const { minify: terserMinify } = require('terser');
const postcss = require('postcss');
const cssnano = require('cssnano');

// Parse command line arguments
let socketPath = null;

for (let i = 2; i < process.argv.length; i++) {
    const arg = process.argv[i];
    if (arg.startsWith('--socket=')) {
        socketPath = arg.substring('--socket='.length);
    }
}

if (!socketPath) {
    console.error('Usage: node minify-server.js --socket=/path/to/socket');
    process.exit(1);
}

// Remove socket if exists
if (fs.existsSync(socketPath)) {
    fs.unlinkSync(socketPath);
}

/**
 * Minify JavaScript content using Terser
 * Strips sourcemaps, preserves license comments, produces minimal output.
 * When stripConsoleDebug is true, console_debug() calls are dropped via Terser
 * pure_funcs (strict production only - the PHP caller decides, this file never does).
 */
async function minifyJs(content, filename, stripConsoleDebug = false) {
    try {
        // Extract all license comments BEFORE minification
        // These can be lost when Terser transforms/inlines IIFEs
        const licenseComments = [];
        const licenseRegex = /\/\*![\s\S]*?\*\//g;
        let match;
        while ((match = licenseRegex.exec(content)) !== null) {
            const license = match[0];
            // Only keep unique licenses (avoid duplicates)
            if (!licenseComments.some(l => l === license)) {
                licenseComments.push(license);
            }
        }

        // Remove existing sourcemap comments before minification
        const cleanContent = content.replace(/\/\/[#@]\s*sourceMappingURL=.*/g, '');

        const compressOptions = {
            dead_code: true,
            drop_console: false,  // Keep console.log for now
            drop_debugger: true,
            passes: 2
        };

        // Strip console_debug call sites in strict production. pure_funcs marks the
        // call as side-effect-free so dead_code elimination removes it entirely.
        // Both forms are stripped: the global console_debug() helper (what app code
        // uses) AND the Debugger.console_debug(...) member form the helper delegates
        // to and that framework code (Ajax, Browser) calls directly. The two function
        // DEFINITIONS survive as harmless dead code - only invocations are the goal.
        if (stripConsoleDebug) {
            compressOptions.pure_funcs = ['console_debug', 'Debugger.console_debug'];
        }

        const result = await terserMinify(cleanContent, {
            compress: compressOptions,
            mangle: {
                reserved: ['$', 'jQuery', '_', 'Rsx', 'rsxapp']  // Don't mangle these
            },
            format: {
                // Don't try to preserve comments - we'll prepend them manually
                comments: false,
                semicolons: true
            },
            sourceMap: false  // No sourcemap in production
        });

        if (result.code === undefined) {
            throw new Error('Terser produced no output');
        }

        // Prepend extracted license comments to output
        let finalCode = result.code;
        if (licenseComments.length > 0) {
            finalCode = licenseComments.join('\n') + '\n' + result.code;
        }

        return {
            status: 'success',
            result: finalCode,
            originalSize: content.length,
            minifiedSize: finalCode.length
        };
    } catch (error) {
        return {
            status: 'error',
            error: {
                type: error.constructor.name,
                message: error.message,
                file: filename
            }
        };
    }
}

/**
 * Minify CSS content using cssnano
 * Strips sourcemaps, preserves license comments, produces minimal output
 */
async function minifyCss(content, filename) {
    try {
        // Extract all license comments BEFORE minification
        const licenseComments = [];
        const licenseRegex = /\/\*![\s\S]*?\*\//g;
        let match;
        while ((match = licenseRegex.exec(content)) !== null) {
            const license = match[0];
            if (!licenseComments.some(l => l === license)) {
                licenseComments.push(license);
            }
        }

        // Remove existing sourcemap comments before minification
        const cleanContent = content.replace(/\/\*[#@]\s*sourceMappingURL=.*?\*\//g, '');

        const result = await postcss([
            cssnano({
                preset: ['default', {
                    // Strip all comments - we'll prepend licenses manually
                    discardComments: { removeAll: true },
                    normalizeWhitespace: true,
                    minifySelectors: true,
                    minifyParams: true,
                    minifyFontValues: true
                }]
            })
        ]).process(cleanContent, {
            from: filename,
            map: false  // No sourcemap in production
        });

        // Prepend extracted license comments to output
        let finalCss = result.css;
        if (licenseComments.length > 0) {
            finalCss = licenseComments.join('\n') + '\n' + result.css;
        }

        return {
            status: 'success',
            result: finalCss,
            originalSize: content.length,
            minifiedSize: finalCss.length
        };
    } catch (error) {
        return {
            status: 'error',
            error: {
                type: error.constructor.name,
                message: error.message,
                file: filename
            }
        };
    }
}

/**
 * Handle incoming RPC requests
 */
async function handleRequest(data) {
    try {
        const request = JSON.parse(data);

        switch (request.method) {
            case 'ping':
                return JSON.stringify({
                    id: request.id,
                    result: 'pong'
                }) + '\n';

            case 'minify':
                const results = {};

                for (const file of request.files) {
                    const type = file.type;  // 'js' or 'css'
                    const content = file.content;
                    const filename = file.filename || 'unknown';
                    const stripConsoleDebug = file.strip_console_debug === true;

                    if (type === 'js') {
                        results[filename] = await minifyJs(content, filename, stripConsoleDebug);
                    } else if (type === 'css') {
                        results[filename] = await minifyCss(content, filename);
                    } else {
                        results[filename] = {
                            status: 'error',
                            error: {
                                type: 'InvalidType',
                                message: `Unknown file type: ${type}`
                            }
                        };
                    }
                }

                return JSON.stringify({
                    id: request.id,
                    results: results
                }) + '\n';

            case 'shutdown':
                return JSON.stringify({
                    id: request.id,
                    result: 'shutting down'
                }) + '\n';

            default:
                return JSON.stringify({
                    id: request.id,
                    error: 'Unknown method: ' + request.method
                }) + '\n';
        }
    } catch (error) {
        return JSON.stringify({
            error: 'Invalid JSON request: ' + error.message
        }) + '\n';
    }
}

// Create the server
const server = net.createServer((socket) => {
    let buffer = '';

    socket.on('data', async (data) => {
        buffer += data.toString();

        let newlineIndex;
        while ((newlineIndex = buffer.indexOf('\n')) !== -1) {
            const line = buffer.substring(0, newlineIndex);
            buffer = buffer.substring(newlineIndex + 1);

            if (line.trim()) {
                const response = await handleRequest(line);
                socket.write(response);

                try {
                    const request = JSON.parse(line);
                    if (request.method === 'shutdown') {
                        socket.end();
                        server.close(() => {
                            if (fs.existsSync(socketPath)) {
                                fs.unlinkSync(socketPath);
                            }
                            process.exit(0);
                        });
                    }
                } catch (e) {
                    // Ignore parse errors for shutdown check
                }
            }
        }
    });

    socket.on('error', (err) => {
        console.error('Socket error:', err);
    });
});

server.listen(socketPath, () => {
    console.log('Minify RPC server listening on ' + socketPath);
});

server.on('error', (err) => {
    console.error('Server error:', err);
    process.exit(1);
});

// Graceful shutdown handlers
process.on('SIGTERM', () => {
    server.close(() => {
        if (fs.existsSync(socketPath)) {
            fs.unlinkSync(socketPath);
        }
        process.exit(0);
    });
});

process.on('SIGINT', () => {
    server.close(() => {
        if (fs.existsSync(socketPath)) {
            fs.unlinkSync(socketPath);
        }
        process.exit(0);
    });
});
