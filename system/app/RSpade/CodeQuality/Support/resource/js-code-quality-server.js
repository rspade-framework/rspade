#!/usr/bin/env node

/**
 * JavaScript Code Quality RPC Server
 *
 * Combines JavaScript linting (Babel parser) and this-usage analysis (Acorn)
 * into a single persistent server to avoid spawning thousands of Node processes.
 *
 * Usage:
 *   Server mode: node js-code-quality-server.js --socket=/path/to/socket
 *
 * RPC Methods:
 *   - ping: Health check
 *   - lint: Check JavaScript syntax using Babel parser
 *   - analyze_this: Analyze 'this' usage patterns using Acorn
 *   - shutdown: Graceful server termination
 *
 * @FILENAME-CONVENTION-EXCEPTION - Node.js RPC server script
 */

const fs = require('fs');
const path = require('path');
const net = require('net');

// Resolve to system/node_modules since that's where packages are installed
const systemDir = path.resolve(__dirname, '../../../../..');
const babelParser = require(path.join(systemDir, 'node_modules', '@babel', 'parser'));
const acorn = require(path.join(systemDir, 'node_modules', 'acorn'));
const walk = require(path.join(systemDir, 'node_modules', 'acorn-walk'));

// Parse command line arguments
let socketPath = null;

for (let i = 2; i < process.argv.length; i++) {
    const arg = process.argv[i];
    if (arg.startsWith('--socket=')) {
        socketPath = arg.substring('--socket='.length);
    }
}

if (!socketPath) {
    console.error('Usage: node js-code-quality-server.js --socket=/path/to/socket');
    process.exit(1);
}

// =============================================================================
// LINTING LOGIC (Babel Parser)
// =============================================================================

/**
 * Lint JavaScript file for syntax errors using Babel parser
 * @param {string} content - File content
 * @param {string} filePath - File path for error reporting
 * @returns {object|null} Error object or null if no errors
 */
function lintFile(content, filePath) {
    try {
        babelParser.parse(content, {
            sourceType: 'module',
            plugins: [
                'decorators-legacy',
                'classProperties',
                'classPrivateProperties',
                'classPrivateMethods',
                'optionalChaining',
                'nullishCoalescingOperator',
                'asyncGenerators',
                'bigInt',
                'dynamicImport',
                'exportDefaultFrom',
                'exportNamespaceFrom',
                'objectRestSpread',
                'topLevelAwait'
            ]
        });

        // No syntax errors
        return null;

    } catch (error) {
        return {
            message: error.message,
            line: error.loc ? error.loc.line : null,
            column: error.loc ? error.loc.column : null
        };
    }
}

// =============================================================================
// THIS-USAGE ANALYSIS LOGIC (Acorn Parser)
// =============================================================================

// Known jQuery callback methods - used for better remediation messages
const JQUERY_CALLBACKS = new Set([
    'click', 'dblclick', 'mouseenter', 'mouseleave', 'mousedown', 'mouseup',
    'mousemove', 'mouseover', 'mouseout', 'change', 'submit', 'focus', 'blur',
    'keydown', 'keyup', 'keypress', 'resize', 'scroll', 'select', 'load',
    'on', 'off', 'one', 'each', 'map', 'filter',
    'fadeIn', 'fadeOut', 'slideDown', 'slideUp', 'animate',
    'done', 'fail', 'always', 'then', 'ready', 'hover'
]);

/**
 * Analyze JavaScript file for 'this' usage violations
 * @param {string} content - File content
 * @param {string} filePath - File path for error reporting
 * @returns {object} Result with violations array or error
 */
function analyzeThisUsage(content, filePath) {
    const lines = content.split('\n');

    let ast;
    try {
        ast = acorn.parse(content, {
            ecmaVersion: 2020,
            sourceType: 'module',
            locations: true
        });
    } catch (e) {
        // Parse error - return empty violations
        return { violations: [], error: `Parse error: ${e.message}` };
    }

    const violations = [];
    const classInfo = new Map();

    // First pass: identify all classes and their types
    walk.simple(ast, {
        ClassDeclaration(node) {
            const hasStaticInit = node.body.body.some(member =>
                member.static && member.key?.name === 'init'
            );
            classInfo.set(node.id.name, {
                isStatic: hasStaticInit
            });
        }
    });

    // Helper to check if first line of function has valid pattern
    function checkFirstLinePattern(funcNode) {
        if (!funcNode.body || !funcNode.body.body || funcNode.body.body.length === 0) {
            return null;
        }

        let checkIndex = 0;
        const firstStmt = funcNode.body.body[0];

        // Check if first statement is e.preventDefault() or similar
        if (firstStmt.type === 'ExpressionStatement' &&
            firstStmt.expression?.type === 'CallExpression' &&
            firstStmt.expression?.callee?.type === 'MemberExpression' &&
            firstStmt.expression?.callee?.property?.name === 'preventDefault') {
            checkIndex = 1;
            if (funcNode.body.body.length <= 1) {
                return null;
            }
        }

        const targetStmt = funcNode.body.body[checkIndex];
        if (targetStmt.type !== 'VariableDeclaration') {
            return null;
        }

        const firstDecl = targetStmt.declarations[0];
        if (!firstDecl || !firstDecl.init) {
            return null;
        }

        const varKind = targetStmt.kind;

        // Check for 'that = this' pattern
        if (firstDecl.id.name === 'that' &&
            firstDecl.init.type === 'ThisExpression') {
            if (varKind !== 'const') {
                return 'that-pattern-wrong-kind';
            }
            return 'that-pattern';
        }

        // Check for 'CurrentClass = this' pattern
        if (firstDecl.id.name === 'CurrentClass' &&
            firstDecl.init.type === 'ThisExpression') {
            if (varKind !== 'const') {
                return 'currentclass-pattern-wrong-kind';
            }
            return 'currentclass-pattern';
        }

        // Check for '$var = $(this)' pattern
        if (firstDecl.id.name.startsWith('$') &&
            firstDecl.init.type === 'CallExpression' &&
            firstDecl.init.callee.name === '$' &&
            firstDecl.init.arguments.length === 1 &&
            firstDecl.init.arguments[0].type === 'ThisExpression') {
            if (varKind !== 'const') {
                return 'jquery-pattern-wrong-kind';
            }
            return 'jquery-pattern';
        }

        return null;
    }

    // Helper to detect if we're in a jQuery callback
    function isLikelyJQueryCallback(ancestors) {
        for (let i = ancestors.length - 1; i >= 0; i--) {
            const node = ancestors[i];
            if (node.type === 'CallExpression' && node.callee.type === 'MemberExpression') {
                const methodName = node.callee.property.name;
                if (JQUERY_CALLBACKS.has(methodName)) {
                    return true;
                }
            }
        }
        return false;
    }

    // Walk the AST looking for 'this' usage
    walk.ancestor(ast, {
        ThisExpression(node, ancestors) {
            // Skip arrow functions - they inherit 'this'
            for (const ancestor of ancestors) {
                if (ancestor.type === 'ArrowFunctionExpression') {
                    return;
                }
            }

            // Find containing function and class
            let containingFunc = null;
            let containingClass = null;
            let isAnonymousFunc = false;
            let isStaticMethod = false;
            let isConstructor = false;
            let isInstanceMethod = false;
            let hasMethodDefinition = false;

            // First pass: check if we're in a MethodDefinition
            for (let i = ancestors.length - 1; i >= 0; i--) {
                const ancestor = ancestors[i];
                if (ancestor.type === 'MethodDefinition') {
                    hasMethodDefinition = true;
                    isStaticMethod = ancestor.static;
                    isConstructor = ancestor.kind === 'constructor';
                    isInstanceMethod = !ancestor.static && ancestor.kind !== 'constructor';
                    break;
                }
            }

            // Second pass: find function and class
            for (let i = ancestors.length - 1; i >= 0; i--) {
                const ancestor = ancestors[i];

                if (!containingFunc && (
                    ancestor.type === 'FunctionExpression' ||
                    ancestor.type === 'FunctionDeclaration'
                )) {
                    containingFunc = ancestor;
                    isAnonymousFunc = ancestor.type === 'FunctionExpression' && !hasMethodDefinition;
                }

                if (!containingClass && (
                    ancestor.type === 'ClassDeclaration' ||
                    ancestor.type === 'ClassExpression'
                )) {
                    containingClass = ancestor;
                }
            }

            if (!containingFunc) {
                return;
            }

            // Skip constructors and instance methods
            if (isConstructor || isInstanceMethod) {
                return;
            }

            // Check if this is part of the allowed first-line pattern
            const parent = ancestors[ancestors.length - 2];
            const firstStmt = containingFunc.body?.body?.[0];
            let checkIndex = 0;

            const hasPreventDefault = firstStmt?.type === 'ExpressionStatement' &&
                firstStmt?.expression?.type === 'CallExpression' &&
                firstStmt?.expression?.callee?.type === 'MemberExpression' &&
                firstStmt?.expression?.callee?.property?.name === 'preventDefault';

            if (hasPreventDefault) {
                checkIndex = 1;
            }

            const targetStmt = containingFunc.body?.body?.[checkIndex];
            const isTargetConst = targetStmt?.type === 'VariableDeclaration' && targetStmt?.kind === 'const';

            // Check if this 'this' is inside $(this) on the first or second line
            if (parent && parent.type === 'CallExpression' && parent.callee?.name === '$') {
                if (isTargetConst &&
                    targetStmt?.declarations?.[0]?.init === parent &&
                    targetStmt?.declarations?.[0]?.id?.name?.startsWith('$')) {
                    return;
                }
            }

            // Check if this 'this' is the 'const that = this' in correct position
            if (parent && parent.type === 'VariableDeclarator' &&
                parent.id?.name === 'that' &&
                isTargetConst &&
                targetStmt?.declarations?.[0]?.id?.name === 'that') {
                return;
            }

            // Check if this 'this' is the 'const CurrentClass = this' in correct position
            if (parent && parent.type === 'VariableDeclarator' &&
                parent.id?.name === 'CurrentClass' &&
                isTargetConst &&
                targetStmt?.declarations?.[0]?.id?.name === 'CurrentClass') {
                return;
            }

            // Check what pattern is used
            const pattern = checkFirstLinePattern(containingFunc);

            // Determine the violation and remediation
            let message = '';
            let remediation = '';
            const lineNum = node.loc.start.line;
            const codeSnippet = lines[lineNum - 1].trim();
            const className = containingClass?.id?.name || 'unknown';
            const isJQueryContext = isLikelyJQueryCallback(ancestors);

            if (isAnonymousFunc) {
                if (!pattern) {
                    const firstStmt = containingFunc.body?.body?.[0];
                    const hasPreventDefault = firstStmt?.type === 'ExpressionStatement' &&
                        firstStmt?.expression?.type === 'CallExpression' &&
                        firstStmt?.expression?.callee?.type === 'MemberExpression' &&
                        firstStmt?.expression?.callee?.property?.name === 'preventDefault';

                    if (isJQueryContext) {
                        message = `'this' in jQuery callback should be aliased for clarity.`;
                        if (hasPreventDefault) {
                            remediation = `Add 'const $element = $(this);' as the second line (after preventDefault), then use $element instead of 'this'.`;
                        } else {
                            remediation = `Add 'const $element = $(this);' as the first line of this function, then use $element instead of 'this'.`;
                        }
                    } else {
                        message = `Ambiguous 'this' usage in anonymous function.`;
                        if (hasPreventDefault) {
                            remediation = `If this is a jQuery callback: Add 'const $element = $(this);' as the second line (after preventDefault).\n` +
                                       `If this is an instance context: Add 'const that = this;' as the second line.\n` +
                                       `Then use the aliased variable instead of 'this'.`;
                        } else {
                            remediation = `If this is a jQuery callback: Add 'const $element = $(this);' as first line.\n` +
                                       `If this is an instance context: Add 'const that = this;' as first line.\n` +
                                       `Then use the aliased variable instead of 'this'.`;
                        }
                    }
                } else if (pattern === 'that-pattern' || pattern === 'jquery-pattern') {
                    message = `'this' used after aliasing. Use the aliased variable instead.`;
                    let varDeclIndex = 0;
                    const firstStmt = containingFunc.body?.body?.[0];
                    if (firstStmt?.type === 'ExpressionStatement' &&
                        firstStmt?.expression?.callee?.property?.name === 'preventDefault') {
                        varDeclIndex = 1;
                    }
                    const varName = containingFunc.body?.body?.[varDeclIndex]?.declarations?.[0]?.id?.name;
                    remediation = pattern === 'jquery-pattern'
                        ? `You already have 'const ${varName} = $(this)'. Use that variable instead of 'this'.`
                        : `You already have 'const that = this'. Use 'that' instead of 'this'.`;
                } else if (pattern === 'that-pattern-wrong-kind') {
                    message = `Instance alias must use 'const', not 'let' or 'var'.`;
                    remediation = `Change to 'const that = this;' - the instance reference should never be reassigned.`;
                } else if (pattern === 'jquery-pattern-wrong-kind') {
                    message = `jQuery element alias must use 'const', not 'let' or 'var'.`;
                    remediation = `Change to 'const $element = $(this);' - jQuery element references should never be reassigned.`;
                }
            } else if (isStaticMethod) {
                if (!pattern) {
                    message = `Static method in '${className}' should not use naked 'this'.`;
                    remediation = `Static methods have two options:\n` +
                                 `1. If you need the exact class (no polymorphism): Replace 'this' with '${className}'\n` +
                                 `2. If you need polymorphism (inherited classes): Add 'const CurrentClass = this;' as first line\n` +
                                 `   Then use CurrentClass.name or CurrentClass.property for polymorphic access.\n` +
                                 `Consider: Does this.name need to work for child classes? If yes, use CurrentClass pattern.`;
                } else if (pattern === 'currentclass-pattern') {
                    message = `'this' used after aliasing to CurrentClass. Use 'CurrentClass' instead.`;
                    remediation = `You already have 'const CurrentClass = this;'. Use 'CurrentClass' for all access, not naked 'this'.`;
                } else if (pattern === 'currentclass-pattern-wrong-kind') {
                    message = `CurrentClass pattern must use 'const', not 'let' or 'var'.`;
                    remediation = `Change to 'const CurrentClass = this;' - the CurrentClass reference should never be reassigned.`;
                } else if (pattern === 'jquery-pattern') {
                    return;
                } else if (pattern === 'jquery-pattern-wrong-kind') {
                    message = `jQuery element alias must use 'const', not 'let' or 'var'.`;
                    remediation = `Change to 'const $element = $(this);' - jQuery element references should never be reassigned.`;
                }

                if (isAnonymousFunc && !pattern) {
                    remediation += `\nException: If this is a jQuery callback, add 'const $element = $(this);' as the first line.`;
                }
            }

            if (message) {
                violations.push({
                    line: lineNum,
                    message: message,
                    codeSnippet: codeSnippet,
                    remediation: remediation
                });
            }
        }
    });

    return { violations: violations };
}

// =============================================================================
// RPC SERVER
// =============================================================================

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

            case 'lint':
                const lintResults = {};
                for (const file of request.files) {
                    try {
                        const content = fs.readFileSync(file, 'utf8');
                        const error = lintFile(content, file);
                        lintResults[file] = {
                            status: 'success',
                            error: error
                        };
                    } catch (error) {
                        lintResults[file] = {
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
                    results: lintResults
                }) + '\n';

            case 'analyze_this':
                const thisResults = {};
                for (const file of request.files) {
                    try {
                        const content = fs.readFileSync(file, 'utf8');
                        const result = analyzeThisUsage(content, file);
                        thisResults[file] = {
                            status: 'success',
                            violations: result.violations,
                            error: result.error || null
                        };
                    } catch (error) {
                        thisResults[file] = {
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
                    results: thisResults
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
    console.log('JS Code Quality RPC server listening on ' + socketPath);
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
