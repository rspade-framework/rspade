#!/usr/bin/env node

/**
 * JavaScript Sanitizer RPC Server
 *
 * This script removes comments and replaces string contents with spaces
 * while preserving line numbers and column positions for accurate violation reporting.
 *
 * Usage:
 *   CLI mode:    node js-sanitizer-server.js <input-file>
 *   Server mode: node js-sanitizer-server.js --socket=/path/to/socket
 */

const fs = require('fs');
const decomment = require('decomment');
const acorn = require('acorn');
const net = require('net');

// Parse command line arguments
let mode = 'cli'; // 'cli' or 'server'
let socketPath = null;
let filePath = null;

for (let i = 2; i < process.argv.length; i++) {
    const arg = process.argv[i];
    if (arg.startsWith('--socket=')) {
        mode = 'server';
        socketPath = arg.substring('--socket='.length);
    } else if (!filePath) {
        filePath = arg;
    }
}

// =============================================================================
// SHARED SANITIZATION LOGIC
// =============================================================================

/**
 * Sanitize JavaScript file content
 * @param {string} content - File content
 * @param {string} filePath - File path for error reporting
 * @returns {string} Sanitized content
 */
function sanitizeContent(content, filePath) {
    // Step 1: Remove comments while preserving spaces
    let sanitized;
    try {
        sanitized = decomment(content, {
            space: true,  // Replace comments with spaces to preserve line numbers
            tolerant: true // Handle potential syntax issues
        });
    } catch (error) {
        // If decomment fails, continue with original code
        sanitized = content;
    }

    // Step 2: Parse the code to find string literals and replace their contents
    try {
        // Parse with location tracking
        const ast = acorn.parse(sanitized, {
            ecmaVersion: 'latest',
            sourceType: 'module',
            locations: true,
            ranges: true,
            allowReturnOutsideFunction: true,
            allowImportExportEverywhere: true,
            allowAwaitOutsideFunction: true,
            allowSuperOutsideMethod: true,
            allowHashBang: true,
            onComment: () => {} // Ignore comments (already removed)
        });

        // Convert code to array of characters for manipulation
        const chars = sanitized.split('');

        // Function to replace string content with spaces
        function replaceStringContent(node) {
            if (node.type === 'Literal' && typeof node.value === 'string') {
                // For string literals, replace the content (but keep the quotes)
                const start = node.range[0];
                const end = node.range[1];

                // Replace everything between quotes with spaces
                for (let i = start + 1; i < end - 1; i++) {
                    if (chars[i] !== '\n') {  // Preserve newlines for line counting
                        chars[i] = ' ';
                    }
                }
            } else if (node.type === 'TemplateLiteral') {
                // For template literals, replace the raw text parts
                for (let quasi of node.quasis) {
                    const start = quasi.range[0];
                    const end = quasi.range[1];

                    // Replace content between backticks or ${...}
                    for (let i = start + 1; i < end - 1; i++) {
                        if (chars[i] !== '\n' && chars[i] !== '$' && chars[i] !== '{' && chars[i] !== '}') {
                            chars[i] = ' ';
                        }
                    }
                }
            }
        }

        // Walk the AST to find all string literals
        function walk(node) {
            if (!node) return;

            // Process current node
            replaceStringContent(node);

            // Recursively process all child nodes
            for (let key in node) {
                if (key === 'range' || key === 'loc' || key === 'start' || key === 'end') {
                    continue; // Skip location properties
                }

                const value = node[key];
                if (Array.isArray(value)) {
                    for (let item of value) {
                        if (typeof item === 'object' && item !== null) {
                            walk(item);
                        }
                    }
                } else if (typeof value === 'object' && value !== null) {
                    walk(value);
                }
            }
        }

        // Walk the AST to process all strings
        walk(ast);

        // Convert back to string
        sanitized = chars.join('');

    } catch (error) {
        // If parsing fails (e.g., syntax error), we still have comments removed
        // Just continue with the decommented version
    }

    return sanitized;
}

// =============================================================================
// MODE HANDLING: CLI or RPC Server
// =============================================================================

if (mode === 'server') {
    // RPC Server Mode
    if (!socketPath) {
        console.error('Server mode requires --socket=/path/to/socket');
        process.exit(1);
    }

    // Remove socket if exists
    if (fs.existsSync(socketPath)) {
        fs.unlinkSync(socketPath);
    }

    function handleRequest(data) {
        try {
            const request = JSON.parse(data);

            switch (request.method) {
                case 'ping':
                    return JSON.stringify({
                        id: request.id,
                        result: 'pong'
                    }) + '\n';

                case 'sanitize':
                    const results = {};
                    for (const file of request.files) {
                        try {
                            const content = fs.readFileSync(file, 'utf8');
                            const sanitized = sanitizeContent(content, file);
                            results[file] = {
                                status: 'success',
                                sanitized: sanitized,
                                original_lines: content.split('\n').length
                            };
                        } catch (error) {
                            results[file] = {
                                status: 'error',
                                error: {
                                    type: 'FileReadError',
                                    message: error.message
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

    const server = net.createServer((socket) => {
        let buffer = '';

        socket.on('data', (data) => {
            buffer += data.toString();

            let newlineIndex;
            while ((newlineIndex = buffer.indexOf('\n')) !== -1) {
                const line = buffer.substring(0, newlineIndex);
                buffer = buffer.substring(newlineIndex + 1);

                if (line.trim()) {
                    const response = handleRequest(line);
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
                        // Ignore
                    }
                }
            }
        });

        socket.on('error', (err) => {
            console.error('Socket error:', err);
        });
    });

    server.listen(socketPath, () => {
        console.log('JS Sanitizer RPC server listening on ' + socketPath);
    });

    server.on('error', (err) => {
        console.error('Server error:', err);
        process.exit(1);
    });

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
} else {
    // CLI Mode
    if (!filePath) {
        console.error('Usage: node js-sanitizer-server.js <input-file>');
        console.error('   or: node js-sanitizer-server.js --socket=/path/to/socket');
        process.exit(1);
    }

    // Read file
    let content;
    try {
        content = fs.readFileSync(filePath, 'utf8');
    } catch (error) {
        console.error(`Error reading file: ${error.message}`);
        process.exit(1);
    }

    // Sanitize file
    const sanitized = sanitizeContent(content, filePath);

    // Output sanitized code to stdout
    process.stdout.write(sanitized);
}
