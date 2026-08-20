#!/usr/bin/env node

/**
 * JQHTML Compilation RPC Server
 *
 * This script compiles .jqhtml templates to JavaScript with sourcemap support.
 *
 * Usage:
 *   CLI mode:    node jqhtml-compile-server.js <input-file> [--format iife] [--sourcemap]
 *   Server mode: node jqhtml-compile-server.js --socket=/path/to/socket
 */

import fs from 'fs';
import path from 'path';
import net from 'net';
import { fileURLToPath } from 'url';
import { compileTemplate } from '@jqhtml/parser';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Get @jqhtml/parser version
const packagePath = path.join(__dirname, '../../../node_modules/@jqhtml/parser/package.json');
let VERSION = '2.2.0';
try {
    const packageJson = JSON.parse(fs.readFileSync(packagePath, 'utf-8'));
    VERSION = packageJson.version;
} catch (e) {
    // Use default version if package.json not found
}

// Parse command line arguments
let mode = 'cli'; // 'cli' or 'server'
let socketPath = null;
let filePath = null;
let format = 'iife';
let sourcemap = false;

for (let i = 2; i < process.argv.length; i++) {
    const arg = process.argv[i];
    if (arg.startsWith('--socket=')) {
        mode = 'server';
        socketPath = arg.substring('--socket='.length);
    } else if (arg === '--format') {
        format = process.argv[++i] || 'iife';
    } else if (arg === '--sourcemap') {
        sourcemap = true;
    } else if (!filePath && !arg.startsWith('--')) {
        filePath = arg;
    }
}

// =============================================================================
// SHARED COMPILATION LOGIC
// =============================================================================

/**
 * Compile a single JQHTML template
 * @param {string} filePath - Path to .jqhtml file
 * @param {object} options - Compilation options
 * @returns {object} Compilation result or error object
 */
function compileFile(filePath, options = {}) {
    try {
        // Read input file
        const inputPath = path.resolve(filePath);
        if (!fs.existsSync(inputPath)) {
            return {
                status: 'error',
                error: {
                    type: 'FileNotFoundError',
                    message: `Input file not found: ${inputPath}`,
                    file: filePath
                }
            };
        }

        const source = fs.readFileSync(inputPath, 'utf-8');

        // Use relative path for sourcemap sources (not just basename)
        // This allows browser devtools to show proper file paths
        const relativePath = path.relative(process.cwd(), inputPath);

        // Compile using @jqhtml/parser API
        const compiled = compileTemplate(source, relativePath, {
            format: options.format || 'iife',
            sourcemap: options.sourcemap !== false,
            version: VERSION
        });

        return {
            status: 'success',
            result: compiled.code,
            file: filePath
        };

    } catch (error) {
        return {
            status: 'error',
            error: {
                type: error.constructor.name,
                message: error.message,
                file: error.filename || filePath,
                line: error.line || null,
                column: error.column || null,
                context: error.context || null,
                suggestion: error.suggestion || null
            }
        };
    }
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

                case 'compile':
                    const results = {};
                    for (const file of request.files) {
                        const fileFormat = file.format || 'iife';
                        const fileSourcemap = file.sourcemap !== false;

                        const compileResult = compileFile(file.path, {
                            format: fileFormat,
                            sourcemap: fileSourcemap
                        });
                        results[file.path] = compileResult;
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
        console.log('JQHTML Compile RPC server listening on ' + socketPath);
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
        console.error('Usage: node jqhtml-compile-server.js <input-file> [--format iife] [--sourcemap]');
        console.error('   or: node jqhtml-compile-server.js --socket=/path/to/socket');
        process.exit(1);
    }

    // Compile file
    const result = compileFile(filePath, { format, sourcemap });

    // Output result
    if (result.status === 'error') {
        console.error('Compilation error:', result.error.message);
        if (result.error.line) {
            console.error(`  at line ${result.error.line}, column ${result.error.column || 0}`);
        }
        if (result.error.context) {
            console.error(`  context: ${result.error.context}`);
        }
        if (result.error.suggestion) {
            console.error(`  suggestion: ${result.error.suggestion}`);
        }
        process.exit(1);
    } else {
        // Output compiled code to stdout
        process.stdout.write(result.result);
    }
}
