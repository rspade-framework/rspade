#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const babel = require('@babel/core');

// Parse command line arguments
let filePath = null;
let target = 'modern';
let hashPath = null;
let jsonOutput = false;

// Process arguments
for (let i = 2; i < process.argv.length; i++) {
    const arg = process.argv[i];

    if (arg === '--json') {
        jsonOutput = true;
    } else if (!filePath) {
        filePath = arg;
    } else if (!target || target === 'modern') {
        target = arg;
    } else if (!hashPath) {
        hashPath = arg;
    }
}

// Default hashPath to filePath if not provided
if (!hashPath) {
    hashPath = filePath;
}

// Error helper for JSON output
function outputError(error) {
    if (jsonOutput) {
        const errorObj = {
            status: 'error',
            error: {
                type: error.constructor.name,
                message: error.message,
                line: error.loc ? error.loc.line : null,
                column: error.loc ? error.loc.column : null,
                file: filePath,
                context: null,
                suggestion: null
            }
        };

        // Add suggestions for common errors
        if (error.message.includes('Cannot find module')) {
            errorObj.error.suggestion = 'Missing Babel dependencies. Run: npm install';
        } else if (error.message.includes('Unexpected token')) {
            errorObj.error.suggestion = 'Check JavaScript syntax in the source file';
        } else if (error.message.includes('decorator')) {
            errorObj.error.suggestion = 'Ensure decorators are properly formatted (e.g., @decorator before class/method)';
        }

        console.log(JSON.stringify(errorObj));
    } else {
        console.error(`Transformation error: ${error.message}`);

        if (error.loc) {
            console.error(`  at line ${error.loc.line}, column ${error.loc.column}`);
        }

        // Provide helpful error messages
        if (error.message.includes('Cannot find module')) {
            console.error('\nMissing dependencies. Please run:');
            console.error(`cd ${__dirname} && npm install`);
        } else if (error.message.includes('Unexpected token')) {
            console.error('\nSyntax error in source file. The file may contain invalid JavaScript.');
        } else if (error.message.includes('decorator')) {
            console.error('\nDecorator syntax error. Ensure decorators are properly formatted.');
        }
    }
}

if (!filePath) {
    const error = new Error('No input file specified');
    if (jsonOutput) {
        console.log(JSON.stringify({
            status: 'error',
            error: {
                type: 'ArgumentError',
                message: error.message,
                suggestion: 'Usage: node js-transformer.js [--json] <file-path> [target] [hash-path]'
            }
        }));
    } else {
        console.error('Usage: node js-transformer.js [--json] <file-path> [target] [hash-path]');
        console.error('Targets: modern, es6, es5');
    }
    process.exit(1);
}

// Read file
let content;
try {
    content = fs.readFileSync(filePath, 'utf8');
} catch (error) {
    error.message = `Error reading file: ${error.message}`;
    outputError(error);
    process.exit(1);
}

/**
 * Preprocessor to handle @decorator on standalone functions
 * Converts @decorator to decorator comment when no ES6 classes are present
 */
function preprocessDecorators(content, filePath) {
    // Check if file contains ES6 class declarations
    // Using regex to avoid parsing errors from decorators
    const es6ClassRegex = /^\s*class\s+[A-Z]\w*\s*(?:extends\s+\w+\s*)?\{/m;
    const hasES6Class = es6ClassRegex.test(content);

    if (hasES6Class) {
        // File has ES6 classes, leave @decorator syntax unchanged
        return content;
    }

    // No ES6 classes, convert @decorator to /** @decorator */
    // Match @decorator at the start of a line (with optional whitespace)
    // that appears before a function declaration
    const decoratorRegex = /^(\s*)@decorator\s*\n(\s*(?:async\s+)?function\s+\w+)/gm;

    const processed = content.replace(decoratorRegex, (match, indent, funcDecl) => {
        return `${indent}/** @decorator */\n${funcDecl}`;
    });

    return processed;
}

// Preprocess content before transformation
content = preprocessDecorators(content, filePath);

// Generate file hash for prefixing (HARDCODED - NOT CONFIGURABLE)
// This prevents namespace collisions when files are concatenated in bundles
const fileHash = crypto.createHash('md5')
    .update(hashPath)
    .digest('hex')
    .substring(0, 8);

// Target environment presets
const targetPresets = {
    modern: {
        targets: {
            chrome: '90',
            firefox: '88',
            safari: '14',
            edge: '90'
        }
    },
    es6: {
        targets: {
            chrome: '60',
            firefox: '60',
            safari: '10.1',
            edge: '15'
        }
    },
    es5: {
        targets: {
            ie: '11'
        }
    }
};

// Create custom plugin to prefix generated WeakMap variables and Babel helper functions
// This plugin runs AFTER all other transformations to catch Babel-generated helpers
const prefixGeneratedVariables = function() {
    return {
        name: 'prefix-generated-variables',
        post(file) {
            // Run after all transformations are complete
            const program = file.path;

            // Track all top-level variables and functions that start with underscore
            const generatedNames = new Set();

            // First pass: collect all generated variable and function names at top level
            for (const statement of program.node.body) {
                if (statement.type === 'VariableDeclaration') {
                    for (const declarator of statement.declarations) {
                        const name = declarator.id?.name;
                        if (name && name.startsWith('_')) {
                            generatedNames.add(name);
                        }
                    }
                } else if (statement.type === 'FunctionDeclaration') {
                    const name = statement.id?.name;
                    if (name && name.startsWith('_')) {
                        generatedNames.add(name);
                    }
                }
            }

            // Second pass: rename all references
            if (generatedNames.size > 0) {
                program.traverse({
                    Identifier(idPath) {
                        if (generatedNames.has(idPath.node.name)) {
                            // Don't rename if it's already prefixed
                            if (!idPath.node.name.startsWith(`_${fileHash}`)) {
                                const newName = `_${fileHash}${idPath.node.name}`;
                                idPath.scope.rename(idPath.node.name, newName);
                            }
                        }
                    }
                });
            }
        }
    };
};

try {
    // Configure Babel transformation
    // Use relative path for sourcemap to match SCSS behavior
    const relativeFilePath = path.relative(process.cwd(), filePath);

    const result = babel.transformSync(content, {
        filename: relativeFilePath,
        sourceFileName: relativeFilePath,  // Explicitly set source filename for sourcemap
        sourceMaps: 'inline',
        presets: [
            ['@babel/preset-env', targetPresets[target] || targetPresets.modern]
        ],
        plugins: [
            // Apply custom prefixing plugin first
            prefixGeneratedVariables,

            // Transform decorators (Stage 3 proposal)
            // Note: We're NOT transforming private fields - native support only
            ['@babel/plugin-proposal-decorators', {
                version: '2023-11',
                // Ensure decorators are transpiled to compatible code
            }],

            // Transform class properties
            '@babel/plugin-transform-class-properties',

            // Transform optional chaining and nullish coalescing
            '@babel/plugin-transform-optional-chaining',
            '@babel/plugin-transform-nullish-coalescing-operator'
        ]
    });

    if (!result || !result.code) {
        const error = new Error('Babel transformation produced no output');
        outputError(error);
        process.exit(1);
    }

    // Output result (no banner - concat-js.js handles that)
    if (jsonOutput) {
        console.log(JSON.stringify({
            status: 'success',
            result: result.code,
            file: filePath,
            hash: fileHash
        }));
    } else {
        console.log(result.code);
    }

} catch (error) {
    // Parse Babel error location if available
    if (error.loc) {
        // Babel provides loc.line and loc.column
    } else if (error.codeFrame) {
        // Try to extract line/column from codeFrame
        const lineMatch = error.codeFrame.match(/>\s*(\d+)\s*\|/);
        const colMatch = error.codeFrame.match(/\n\s+\|\s+(\^+)/);
        if (lineMatch) {
            error.loc = {
                line: parseInt(lineMatch[1]),
                column: colMatch ? colMatch[1].indexOf('^') + 1 : 0
            };
        }
    } else if (error.message) {
        // Try to extract from message (e.g., "file.js: Unexpected token (10:5)")
        const match = error.message.match(/\((\d+):(\d+)\)/);
        if (match) {
            error.loc = {
                line: parseInt(match[1]),
                column: parseInt(match[2])
            };
        }
    }

    outputError(error);
    process.exit(1);
}