#!/usr/bin/env node

const fs = require('fs');
const parser = require('@babel/parser');
const traverse = require('@babel/traverse').default;
const t = require('@babel/types');
const generate = require('@babel/generator').default;
const net = require('net');

// Parse command line arguments
let mode = 'cli'; // 'cli' or 'server'
let socketPath = null;
let filePath = null;
let jsonOutput = false;

for (let i = 2; i < process.argv.length; i++) {
    const arg = process.argv[i];
    if (arg.startsWith('--socket=')) {
        mode = 'server';
        socketPath = arg.substring('--socket='.length);
    } else if (arg === '--json') {
        jsonOutput = true;
    } else if (!filePath) {
        filePath = arg;
    }
}

// =============================================================================
// SHARED PARSING LOGIC
// =============================================================================

// Error helper for JSON output
function outputError(error, filePath, jsonOutput) {
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
        if (error.message.includes('Unexpected token')) {
            errorObj.error.suggestion = 'Check JavaScript syntax - may have invalid characters or missing punctuation';
        } else if (error.message.includes('Unterminated')) {
            errorObj.error.suggestion = 'Check for unclosed strings, comments, or brackets';
        }

        return errorObj;
    } else {
        return {
            status: 'error',
            message: `Parse error: ${error.message}`
        };
    }
}

// Custom error for structure violations
function structureError(type, message, line, code, filePath) {
    return {
        status: 'error',
        error: {
            type: type,
            message: message,
            line: line,
            code: code,
            file: filePath
        }
    };
}

// Helper function to extract decorator information in PHP-compatible compact format
function extractDecorators(decorators) {
    if (!decorators || decorators.length === 0) {
        return [];
    }

    return decorators.map(decorator => {
        const expr = decorator.expression;

        // Handle simple decorators like @readonly
        if (t.isIdentifier(expr)) {
            return [expr.name, []];
        }

        // Handle decorators with arguments like @Route('/api')
        if (t.isCallExpression(expr)) {
            const name = t.isIdentifier(expr.callee) ? expr.callee.name :
                       t.isMemberExpression(expr.callee) ? getMemberExpressionName(expr.callee) :
                       'unknown';

            const args = expr.arguments.map(arg => extractArgumentValue(arg));

            return [name, args];
        }

        // Handle member expression decorators like @Namespace.Decorator
        if (t.isMemberExpression(expr)) {
            return [getMemberExpressionName(expr), []];
        }

        return ['unknown', []];
    });
}

// Helper to get full name from member expression
function getMemberExpressionName(node) {
    if (t.isIdentifier(node.object) && t.isIdentifier(node.property)) {
        return `${node.object.name}.${node.property.name}`;
    }
    if (t.isIdentifier(node.property)) {
        return node.property.name;
    }
    return 'unknown';
}

// Helper to extract argument values
function extractArgumentValue(node) {
    if (t.isStringLiteral(node)) {
        return node.value;
    }
    if (t.isNumericLiteral(node)) {
        return node.value;
    }
    if (t.isBooleanLiteral(node)) {
        return node.value;
    }
    if (t.isNullLiteral(node)) {
        return null;
    }
    if (t.isIdentifier(node)) {
        return { identifier: node.name };
    }
    if (t.isObjectExpression(node)) {
        const obj = {};
        node.properties.forEach(prop => {
            if (t.isObjectProperty(prop) && t.isIdentifier(prop.key)) {
                obj[prop.key.name] = extractArgumentValue(prop.value);
            }
        });
        return obj;
    }
    if (t.isArrayExpression(node)) {
        return node.elements.map(el => el ? extractArgumentValue(el) : null);
    }
    if (t.isTemplateLiteral(node) && node.expressions.length === 0) {
        // Simple template literal without expressions
        return node.quasis[0].value.raw;
    }

    // For complex expressions, store the type
    return { type: node.type };
}

// Helper to get snippet of code for error messages
function getCodeSnippet(node) {
    try {
        const generated = generate(node, { compact: true });
        let code = generated.code;
        // Truncate long code snippets
        if (code.length > 60) {
            code = code.substring(0, 60) + '...';
        }
        return code;
    } catch (e) {
        return node.type;
    }
}

// Helper to check if a value is static (no function calls)
function isStaticValue(node) {
    if (!node) return false;

    // Literals are always static
    if (t.isStringLiteral(node) || t.isNumericLiteral(node) ||
        t.isBooleanLiteral(node) || t.isNullLiteral(node)) {
        return true;
    }

    // Template literals without expressions are static
    if (t.isTemplateLiteral(node) && node.expressions.length === 0) {
        return true;
    }

    // Binary expressions with static operands (e.g., 3 * 365)
    if (t.isBinaryExpression(node)) {
        return isStaticValue(node.left) && isStaticValue(node.right);
    }

    // Unary expressions with static operands (e.g., -5, !true)
    if (t.isUnaryExpression(node)) {
        return isStaticValue(node.argument);
    }

    // Arrays with all static elements
    if (t.isArrayExpression(node)) {
        return node.elements.every(el => el === null || isStaticValue(el));
    }

    // Objects with all static properties
    if (t.isObjectExpression(node)) {
        return node.properties.every(prop => {
            if (t.isObjectProperty(prop)) {
                return isStaticValue(prop.value);
            }
            return false; // Methods are not static
        });
    }

    // Everything else (identifiers, function calls, etc.) is not static
    return false;
}

/**
 * Helper function to check if a JSDoc comment contains @decorator tag
 */
function hasDecoratorTag(commentValue) {
    if (!commentValue) return false;

    // Parse JSDoc-style comment
    // Look for @decorator anywhere in the comment
    const lines = commentValue.split('\n');
    for (const line of lines) {
        // Remove leading asterisk and whitespace
        const cleanLine = line.replace(/^\s*\*\s*/, '').trim();
        // Check if line contains @decorator tag
        if (cleanLine === '@decorator' || cleanLine.startsWith('@decorator ')) {
            return true;
        }
    }
    return false;
}

/**
 * Extract JSDoc decorators from comments (e.g., @Instantiatable, @Monoprogenic)
 * Returns compact format matching PHP: [[name, [args]], ...]
 */
function extractJSDocDecorators(leadingComments) {
    const decorators = [];

    if (!leadingComments) return decorators;

    for (const comment of leadingComments) {
        if (comment.type !== 'CommentBlock') continue;

        const lines = comment.value.split('\n');
        for (const line of lines) {
            // Remove leading asterisk and whitespace
            const cleanLine = line.replace(/^\s*\*\s*/, '').trim();

            // Match @DecoratorName pattern (capital letter start, no spaces)
            const match = cleanLine.match(/^@([A-Z][A-Za-z0-9_]*)\s*$/);
            if (match) {
                const decoratorName = match[1];
                // Store in compact format matching PHP: [name, [args]]
                decorators.push([decoratorName, []]);
            }
        }
    }

    return decorators;
}

// Helper functions
function getValueType(node) {
    if (t.isStringLiteral(node)) return `"${node.value}"`;
    if (t.isNumericLiteral(node)) return node.value;
    if (t.isBooleanLiteral(node)) return node.value;
    if (t.isNullLiteral(node)) return null;
    if (t.isIdentifier(node)) return node.name;
    if (t.isArrayExpression(node)) return 'array';
    if (t.isObjectExpression(node)) return 'object';
    if (t.isFunctionExpression(node) || t.isArrowFunctionExpression(node)) return 'function';
    return node.type;
}

function getExportType(node) {
    if (t.isClassDeclaration(node)) return 'class';
    if (t.isFunctionDeclaration(node)) return 'function';
    if (t.isIdentifier(node)) return 'identifier';
    if (t.isCallExpression(node)) return 'expression';
    return node.type;
}

/**
 * Parse a single JavaScript file
 * @param {string} content - File content
 * @param {string} filePath - File path for error reporting
 * @param {boolean} jsonOutput - Whether to output JSON format
 * @returns {object} Parse result or error object
 */
function parseFileContent(content, filePath, jsonOutput) {
    // Parse result object
    const result = {
        classes: {},
        functions: {},
        exports: {},
        imports: [],
        globalFunctions: [],              // All global function names
        globalConstants: [],              // All global const names
        functionsWithDecorators: {}       // Global functions that have decorators
    };

    // Tracking for validation
    let hasES6Class = false;
    let moduleExportsFound = null;
    let codeOutsideAllowed = [];

    try {
        // Parse with Babel
        const ast = parser.parse(content, {
            sourceType: 'module',
            attachComment: true,  // Attach comments to AST nodes for /** @decorator */ detection
            plugins: [
                'jsx',
                'typescript',
                'classProperties',
                'classPrivateProperties',
                'classPrivateMethods',
                ['decorators', { decoratorsBeforeExport: true }],
                'dynamicImport',
                'exportDefaultFrom',
                'exportNamespaceFrom',
                'asyncGenerators',
                'objectRestSpread',
                'optionalCatchBinding',
                'optionalChaining',
                'nullishCoalescingOperator',
                'classStaticBlock'
            ]
        });

        // First pass: Check for classes and track top-level statements
        ast.program.body.forEach(node => {
            if (t.isClassDeclaration(node) || (t.isExportNamedDeclaration(node) && t.isClassDeclaration(node.declaration))) {
                hasES6Class = true;
            }
        });

        // Traverse AST
        traverse(ast, {
            // Class declarations
            ClassDeclaration(path) {
                const className = path.node.id.name;

                // Extract both ES decorators and JSDoc decorators, merge them
                const esDecorators = extractDecorators(path.node.decorators);
                const jsdocDecorators = extractJSDocDecorators(path.node.leadingComments);
                const allDecorators = [...esDecorators, ...jsdocDecorators];

                const classInfo = {
                    name: className,
                    extends: path.node.superClass ? path.node.superClass.name : null,
                    public_instance_methods: {},
                    public_static_methods: {},
                    properties: {},
                    staticProperties: {},
                    decorators: allDecorators,
                    duplicateMethods: []  // Track methods defined more than once
                };

                // Track seen method names to detect duplicates
                const seenMethods = {};  // methodName -> { static: bool, line: number }

                // Extract methods and properties
                path.node.body.body.forEach(member => {
                    if (t.isClassMethod(member)) {
                        // Check for @decorator in JSDoc comments on the method
                        let hasDecoratorComment = false;
                        if (member.leadingComments) {
                            hasDecoratorComment = member.leadingComments.some(comment => {
                                if (comment.type !== 'CommentBlock') return false;
                                return hasDecoratorTag(comment.value);
                            });
                        }

                        // Determine visibility: private if starts with #, otherwise public
                        const methodName = member.key.name || (t.isPrivateName(member.key) ? '#' + member.key.id.name : 'unknown');
                        const visibility = methodName.startsWith('#') ? 'private' : 'public';
                        const methodLine = member.loc ? member.loc.start.line : null;

                        // Check for duplicate method definition
                        const methodKey = (member.static ? 'static:' : 'instance:') + methodName;
                        if (seenMethods[methodKey]) {
                            // This is a duplicate!
                            classInfo.duplicateMethods.push({
                                name: methodName,
                                static: member.static,
                                firstLine: seenMethods[methodKey].line,
                                secondLine: methodLine
                            });
                        } else {
                            seenMethods[methodKey] = { line: methodLine };
                        }

                        const methodInfo = {
                            // PHP-compatible fields
                            name: methodName,
                            static: member.static,
                            visibility: visibility,
                            line: member.loc ? member.loc.start.line : null,

                            // JavaScript-specific fields (keep existing)
                            params: member.params.map(p => {
                                if (t.isIdentifier(p)) return p.name;
                                if (t.isRestElement(p) && t.isIdentifier(p.argument)) return '...' + p.argument.name;
                                if (t.isObjectPattern(p)) return '{object}';
                                if (t.isArrayPattern(p)) return '[array]';
                                return p.type;
                            }),

                            // PHP-compatible parameters structure
                            parameters: member.params.map(p => {
                                const paramInfo = {};
                                if (t.isIdentifier(p)) {
                                    paramInfo.name = p.name;
                                } else if (t.isRestElement(p) && t.isIdentifier(p.argument)) {
                                    paramInfo.name = '...' + p.argument.name;
                                } else if (t.isObjectPattern(p)) {
                                    paramInfo.name = '{object}';
                                } else if (t.isArrayPattern(p)) {
                                    paramInfo.name = '[array]';
                                } else {
                                    paramInfo.name = p.type;
                                }
                                return paramInfo;
                            }),

                            async: member.async,
                            generator: member.generator,
                            kind: member.kind,
                            decorators: extractDecorators(member.decorators),
                            isDecoratorFunction: hasDecoratorComment
                        };

                        if (member.static) {
                            classInfo.public_static_methods[member.key.name] = methodInfo;

                            // Track static methods that are decorator functions
                            if (hasDecoratorComment) {
                                if (!result.functionsWithDecorators[className]) {
                                    result.functionsWithDecorators[className] = {};
                                }
                                result.functionsWithDecorators[className][member.key.name] = {
                                    decorators: [['decorator', []]],
                                    line: member.loc ? member.loc.start.line : null
                                };
                            }
                        } else {
                            classInfo.public_instance_methods[member.key.name] = methodInfo;
                        }
                    } else if (t.isClassProperty(member) || t.isClassPrivateProperty(member)) {
                        const propName = t.isIdentifier(member.key) ? member.key.name :
                                       t.isPrivateName(member.key) ? '#' + member.key.id.name :
                                       'unknown';

                        const propInfo = {
                            name: propName,
                            static: member.static,
                            value: member.value ? getValueType(member.value) : null,
                            decorators: extractDecorators(member.decorators)
                        };

                        if (member.static) {
                            classInfo.staticProperties[propName] = propInfo;
                        } else {
                            classInfo.properties[propName] = propInfo;
                        }
                    }
                });

                result.classes[className] = classInfo;
            },

            // Function declarations
            FunctionDeclaration(path) {
                if (path.node.id) {
                    const funcName = path.node.id.name;

                    // Add to global functions list
                    result.globalFunctions.push(funcName);

                    // Check if it has decorators (either @decorator syntax or /** @decorator */ comment)
                    const decorators = extractDecorators(path.node.decorators);

                    // Check for @decorator in JSDoc comments
                    let hasDecoratorComment = false;
                    if (path.node.leadingComments) {
                        hasDecoratorComment = path.node.leadingComments.some(comment => {
                            if (comment.type !== 'CommentBlock') return false;
                            // Check if JSDoc contains @decorator tag
                            return hasDecoratorTag(comment.value);
                        });
                    }

                    if (decorators || hasDecoratorComment) {
                        result.functionsWithDecorators[funcName] = {
                            decorators: hasDecoratorComment ? [['decorator', []]] : decorators,
                            line: path.node.loc ? path.node.loc.start.line : null
                        };
                    }

                    result.functions[funcName] = {
                        name: funcName,
                        params: path.node.params.map(p => {
                            if (t.isIdentifier(p)) return p.name;
                            if (t.isRestElement(p) && t.isIdentifier(p.argument)) return '...' + p.argument.name;
                            if (t.isObjectPattern(p)) return '{object}';
                            if (t.isArrayPattern(p)) return '[array]';
                            return p.type;
                        }),
                        async: path.node.async,
                        generator: path.node.generator,
                        decorators: decorators
                    };
                }
            },

            // Variable declarations (check for function expressions)
            VariableDeclaration(path) {
                // Only check top-level variables (directly in Program body)
                if (path.parent && path.parent.type === 'Program') {
                    path.node.declarations.forEach(decl => {
                        if (t.isIdentifier(decl.id)) {
                            const varName = decl.id.name;

                            // Check if it's a function expression
                            if (decl.init && (t.isFunctionExpression(decl.init) || t.isArrowFunctionExpression(decl.init))) {
                                // Add to global functions list
                                result.globalFunctions.push(varName);

                                // Check for decorators (function expressions don't support decorators directly)
                                // But we still track them as functions
                                result.functions[varName] = {
                                    name: varName,
                                    params: decl.init.params ? decl.init.params.map(p => {
                                        if (t.isIdentifier(p)) return p.name;
                                        if (t.isRestElement(p) && t.isIdentifier(p.argument)) return '...' + p.argument.name;
                                        if (t.isObjectPattern(p)) return '{object}';
                                        if (t.isArrayPattern(p)) return '[array]';
                                        return p.type;
                                    }) : [],
                                    async: decl.init.async || false,
                                    generator: decl.init.generator || false,
                                    decorators: null
                                };
                            } else if (path.node.kind === 'const' && decl.init && isStaticValue(decl.init)) {
                                // Const with static value - track it
                                result.globalConstants.push(varName);
                            } else if (!hasES6Class) {
                                // Non-allowed variable in a file without classes
                                // (not a function, not a const with static value)
                                codeOutsideAllowed.push({
                                    line: path.node.loc ? path.node.loc.start.line : null,
                                    code: getCodeSnippet(decl)
                                });
                            }
                        }
                    });
                }
            },

            // Check for module.exports
            AssignmentExpression(path) {
                const left = path.node.left;
                if (t.isMemberExpression(left)) {
                    if ((t.isIdentifier(left.object) && left.object.name === 'module') &&
                        (t.isIdentifier(left.property) && left.property.name === 'exports')) {
                        moduleExportsFound = path.node.loc ? path.node.loc.start.line : 1;
                    }
                }
            },

            // Check for exports.something =
            MemberExpression(path) {
                if (path.parent && t.isAssignmentExpression(path.parent) && path.parent.left === path.node) {
                    if (t.isIdentifier(path.node.object) && path.node.object.name === 'exports') {
                        moduleExportsFound = path.node.loc ? path.node.loc.start.line : 1;
                    }
                }
            },

            // Imports
            ImportDeclaration(path) {
                const importInfo = {
                    source: path.node.source.value,
                    specifiers: []
                };

                path.node.specifiers.forEach(spec => {
                    if (t.isImportDefaultSpecifier(spec)) {
                        importInfo.specifiers.push({
                            type: 'default',
                            local: spec.local.name
                        });
                    } else if (t.isImportSpecifier(spec)) {
                        importInfo.specifiers.push({
                            type: 'named',
                            imported: spec.imported.name,
                            local: spec.local.name
                        });
                    } else if (t.isImportNamespaceSpecifier(spec)) {
                        importInfo.specifiers.push({
                            type: 'namespace',
                            local: spec.local.name
                        });
                    }
                });

                result.imports.push(importInfo);
            },

            // Exports
            ExportNamedDeclaration(path) {
                if (path.node.declaration) {
                    if (t.isClassDeclaration(path.node.declaration) && path.node.declaration.id) {
                        result.exports[path.node.declaration.id.name] = 'class';
                    } else if (t.isFunctionDeclaration(path.node.declaration) && path.node.declaration.id) {
                        result.exports[path.node.declaration.id.name] = 'function';
                    } else if (t.isVariableDeclaration(path.node.declaration)) {
                        path.node.declaration.declarations.forEach(decl => {
                            if (t.isIdentifier(decl.id)) {
                                result.exports[decl.id.name] = 'variable';
                            }
                        });
                    }
                }

                // Handle export specifiers
                if (path.node.specifiers) {
                    path.node.specifiers.forEach(spec => {
                        if (t.isExportSpecifier(spec)) {
                            result.exports[spec.exported.name] = 'named';
                        }
                    });
                }
            },

            ExportDefaultDeclaration(path) {
                result.exports.default = getExportType(path.node.declaration);
            },

            Program: {
                exit(path) {
                    // After traversal, check structure violations

                    // Check for module.exports
                    if (moduleExportsFound) {
                        const error = structureError(
                            'ModuleExportsFound',
                            'Module exports detected. JavaScript files are concatenated, use direct class references.',
                            moduleExportsFound,
                            null,
                            filePath
                        );
                        throw error;
                    }

                    // Check if this is a compiled/generated file (not originally a .js file)
                    // These files are generated from other sources (.jqhtml, etc) and should be exempt
                    const firstLine = content.split('\n')[0];
                    if (firstLine && firstLine.includes('Compiled from:')) {
                        // This is a compiled file, skip all structure validation
                        return;
                    }

                    // TEMPORARY: Exempt specific utility files from strict validation
                    // TODO: Replace this with a proper @RULE comment-based system
                    const fileName = filePath.split('/').pop();
                    const exemptFiles = ['functions.js'];
                    if (exemptFiles.includes(fileName)) {
                        // Skip validation for these files temporarily
                        return;
                    }

                    // Check structure based on whether file has ES6 class
                    if (hasES6Class) {
                        // Files with classes should only have class and comments
                        let invalidCode = null;

                        path.node.body.forEach(node => {
                            // Allow comments (handled by parser)
                            if (t.isClassDeclaration(node)) {
                                // Class is allowed
                                return;
                            }
                            if (t.isExportNamedDeclaration(node) && t.isClassDeclaration(node.declaration)) {
                                // Exported class is allowed
                                return;
                            }
                            if (t.isExportDefaultDeclaration(node) && t.isClassExpression(node.declaration)) {
                                // Export default class is allowed
                                return;
                            }
                            if (t.isImportDeclaration(node)) {
                                // Imports are allowed (they're removed during bundling)
                                return;
                            }

                            // Anything else is not allowed
                            if (!invalidCode) {
                                invalidCode = {
                                    line: node.loc ? node.loc.start.line : null,
                                    code: getCodeSnippet(node)
                                };
                            }
                        });

                        if (invalidCode) {
                            const error = structureError(
                                'CodeOutsideClass',
                                'JavaScript files with classes may only contain one class declaration and comments.',
                                invalidCode.line,
                                invalidCode.code,
                                filePath
                            );
                            throw error;
                        }
                    } else {
                        // Files without classes should only have functions and comments
                        let invalidCode = null;

                        path.node.body.forEach(node => {
                            // Allow function declarations
                            if (t.isFunctionDeclaration(node)) {
                                return;
                            }

                            // Allow variable declarations that are functions or static const values
                            if (t.isVariableDeclaration(node)) {
                                const allAllowed = node.declarations.every(decl => {
                                    // Functions are always allowed
                                    if (decl.init && (
                                        t.isFunctionExpression(decl.init) ||
                                        t.isArrowFunctionExpression(decl.init)
                                    )) {
                                        return true;
                                    }

                                    // Const declarations with static values are allowed
                                    if (node.kind === 'const' && decl.init && isStaticValue(decl.init)) {
                                        return true;
                                    }

                                    return false;
                                });
                                if (allAllowed) {
                                    return;
                                }
                            }

                            // Allow imports (they're removed during bundling)
                            if (t.isImportDeclaration(node)) {
                                return;
                            }

                            // Allow exports that wrap functions
                            if (t.isExportNamedDeclaration(node)) {
                                if (t.isFunctionDeclaration(node.declaration)) {
                                    return;
                                }
                                if (!node.declaration && node.specifiers) {
                                    // Export of existing identifiers
                                    return;
                                }
                            }

                            // Anything else is not allowed
                            if (!invalidCode) {
                                invalidCode = {
                                    line: node.loc ? node.loc.start.line : null,
                                    code: getCodeSnippet(node)
                                };
                            }
                        });

                        if (invalidCode) {
                            const error = structureError(
                                'CodeOutsideAllowed',
                                'JavaScript files without classes may only contain function declarations, const variables with static values, and comments.',
                                invalidCode.line,
                                invalidCode.code,
                                filePath
                            );
                            throw error;
                        }
                    }
                }
            }
        });

        return {
            status: 'success',
            result: result,
            file: filePath
        };

    } catch (error) {
        // If error is already a structure error object, return it
        if (error.status === 'error') {
            return error;
        }

        // Parse Babel error location if available
        if (!error.loc && error.message) {
            // Try to extract from message (e.g., "Unexpected token (10:5)")
            const match = error.message.match(/\((\d+):(\d+)\)/);
            if (match) {
                error.loc = {
                    line: parseInt(match[1]),
                    column: parseInt(match[2])
                };
            }
        }

        return outputError(error, filePath, jsonOutput);
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

                case 'parse':
                    const results = {};
                    for (const file of request.files) {
                        try {
                            const content = fs.readFileSync(file, 'utf8');
                            const parseResult = parseFileContent(content, file, true);
                            results[file] = parseResult;
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
        console.log('JS Parser RPC server listening on ' + socketPath);
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
        console.error('Usage: node js-parser-server.js [--json] <file>');
        console.error('   or: node js-parser-server.js --socket=/path/to/socket');
        process.exit(1);
    }

    // Read file
    let content;
    try {
        content = fs.readFileSync(filePath, 'utf8');
    } catch (error) {
        if (jsonOutput) {
            console.log(JSON.stringify({
                status: 'error',
                error: {
                    type: 'FileReadError',
                    message: `Error reading file: ${error.message}`,
                    file: filePath
                }
            }));
        } else {
            console.error(`Error reading file: ${error.message}`);
        }
        process.exit(1);
    }

    // Parse file
    const result = parseFileContent(content, filePath, jsonOutput);

    // Output result
    if (jsonOutput) {
        console.log(JSON.stringify(result));
    } else {
        if (result.status === 'error') {
            console.error('Parse error:', result.error || result.message);
            process.exit(1);
        } else {
            console.log(JSON.stringify(result.result, null, 2));
        }
    }
}
