"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || function (mod) {
    if (mod && mod.__esModule) return mod;
    var result = {};
    if (mod != null) for (var k in mod) if (k !== "default" && Object.prototype.hasOwnProperty.call(mod, k)) __createBinding(result, mod, k);
    __setModuleDefault(result, mod);
    return result;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.JqhtmlLifecycleDiagnosticProvider = exports.JqhtmlLifecycleHoverProvider = exports.JqhtmlLifecycleSemanticTokensProvider = void 0;
const vscode = __importStar(require("vscode"));
const ide_bridge_client_1 = require("./ide_bridge_client");
/**
 * JQHTML lifecycle methods that are called automatically by the framework
 */
const JQHTML_LIFECYCLE_METHODS = ['on_render', 'on_create', 'on_load', 'on_ready', 'on_stop', 'cache_id'];
/**
 * Convention methods that are called automatically by the RSX framework
 */
const CONVENTION_METHODS = [
    '_on_framework_core_define',
    '_on_framework_modules_define',
    '_on_framework_core_init',
    'on_app_modules_define',
    'on_app_define',
    '_on_framework_modules_init',
    'on_app_modules_init',
    'on_app_init',
    'on_app_ready',
];
/**
 * Lifecycle method documentation
 */
const LIFECYCLE_DOCS = {
    on_render: 'Initial render phase - template DOM created. Parent completes before children. DOM manipulation allowed. MUST be synchronous.',
    on_create: 'Quick setup after DOM exists. Children complete before parent. DOM manipulation allowed. MUST be synchronous.',
    on_load: 'Data loading phase - fetch async data. NO DOM manipulation allowed, only update `this.data`. Template re-renders after load. MUST be async.',
    on_ready: 'Final setup phase - all data loaded. Children complete before parent. DOM manipulation allowed. Can be sync or async.',
    on_stop: 'Component destruction phase - cleanup resources. Called before component is removed. MUST be synchronous.',
    cache_id: 'Returns a unique cache key for this component instance. Used by framework to cache/restore component state. Return null to disable caching.',
};
/**
 * Cache for subclass checks
 */
const subclass_cache = new Map();
/**
 * IDE Bridge client instance (shared across all providers)
 */
let ide_bridge_client = null;
/**
 * Check if a JavaScript class extends another class (anywhere in inheritance chain)
 */
async function is_subclass_of_jqhtml_component(class_name) {
    const cache_key = `${class_name}:Jqhtml_Component`;
    // Check cache first
    if (subclass_cache.has(cache_key)) {
        return subclass_cache.get(cache_key);
    }
    // Initialize IDE bridge client if needed
    if (!ide_bridge_client) {
        const output_channel = vscode.window.createOutputChannel('RSpade JQHTML Lifecycle');
        ide_bridge_client = new ide_bridge_client_1.IdeBridgeClient(output_channel);
    }
    try {
        const response = await ide_bridge_client.js_is_subclass_of(class_name, 'Jqhtml_Component');
        const is_subclass = response.is_subclass || false;
        // Cache the result
        subclass_cache.set(cache_key, is_subclass);
        return is_subclass;
    }
    catch (error) {
        // Re-throw error to fail loud - no silent fallbacks
        throw new Error(`Failed to check if ${class_name} extends Jqhtml_Component: ${error.message}`);
    }
}
/**
 * Extract class name from document text
 */
function extract_class_name(text) {
    const regex = /class\s+([A-Za-z0-9_]+)\s+extends/;
    const match = regex.exec(text);
    return match ? match[1] : null;
}
/**
 * Check if class extends Jqhtml_Component
 */
function directly_extends_jqhtml(text) {
    const regex = /class\s+[A-Za-z0-9_]+\s+extends\s+Jqhtml_Component/;
    return regex.test(text);
}
/**
 * Check if class has extends clause
 */
function has_extends_clause(text) {
    const regex = /class\s+[A-Za-z0-9_]+\s+extends\s+[A-Za-z0-9_]+/;
    return regex.test(text);
}
/**
 * Check if a method is async
 */
function is_async_method(line_text) {
    return line_text.trim().startsWith('async ');
}
/**
 * Check if position is inside a comment
 */
function is_in_comment(document, position) {
    const line_text = document.lineAt(position.line).text;
    const char_pos = position.character;
    // Check for single-line comment
    const single_comment_idx = line_text.indexOf('//');
    if (single_comment_idx !== -1 && single_comment_idx < char_pos) {
        return true;
    }
    // Check for multi-line comment by looking at text before position
    const text_before = document.getText(new vscode.Range(new vscode.Position(0, 0), position));
    let in_block_comment = false;
    let i = 0;
    while (i < text_before.length) {
        if (text_before.substring(i, i + 2) === '/*') {
            in_block_comment = true;
            i += 2;
        }
        else if (text_before.substring(i, i + 2) === '*/') {
            in_block_comment = false;
            i += 2;
        }
        else {
            i++;
        }
    }
    return in_block_comment;
}
/**
 * Provides semantic tokens for JQHTML lifecycle methods (amber color)
 */
class JqhtmlLifecycleSemanticTokensProvider {
    async provideDocumentSemanticTokens(document) {
        console.log(`[JQHTML] provideDocumentSemanticTokens called for: ${document.fileName}`);
        const tokens_builder = new vscode.SemanticTokensBuilder();
        if (document.languageId !== 'javascript' && document.languageId !== 'typescript') {
            console.log(`[JQHTML] Skipping - not JS/TS, language is: ${document.languageId}`);
            return tokens_builder.build();
        }
        const text = document.getText();
        // Quick check: does this file have an extends clause?
        if (!has_extends_clause(text)) {
            console.log(`[JQHTML] No extends clause found, checking for convention methods only`);
            // Still process convention methods even without extends
        }
        else {
            console.log(`[JQHTML] Found extends clause`);
        }
        // Check if directly extends Jqhtml_Component
        const is_jqhtml = directly_extends_jqhtml(text);
        console.log(`[JQHTML] Directly extends Jqhtml_Component: ${is_jqhtml}`);
        // If not directly extending, check inheritance chain
        let extends_jqhtml = is_jqhtml;
        if (!is_jqhtml && has_extends_clause(text)) {
            const class_name = extract_class_name(text);
            console.log(`[JQHTML] Checking inheritance for class: ${class_name}`);
            if (class_name) {
                extends_jqhtml = await is_subclass_of_jqhtml_component(class_name);
                console.log(`[JQHTML] Extends Jqhtml_Component via inheritance: ${extends_jqhtml}`);
            }
        }
        // Highlight lifecycle methods (only if extends Jqhtml_Component)
        if (extends_jqhtml) {
            console.log(`[JQHTML] Scanning for JQHTML lifecycle methods...`);
            let lifecycle_count = 0;
            for (const method_name of JQHTML_LIFECYCLE_METHODS) {
                const regex = new RegExp(`\\b(async\\s+)?(${method_name})\\s*\\(`, 'g');
                let match;
                while ((match = regex.exec(text)) !== null) {
                    const method_start = match.index + (match[1] ? match[1].length : 0);
                    const position = document.positionAt(method_start);
                    // Skip if this is inside a comment
                    if (is_in_comment(document, position)) {
                        console.log(`[JQHTML] Skipping ${method_name} - inside comment`);
                        continue;
                    }
                    console.log(`[JQHTML] Found lifecycle method: ${method_name} at line ${position.line}`);
                    tokens_builder.push(position.line, position.character, method_name.length, 0, 0);
                    lifecycle_count++;
                }
            }
            console.log(`[JQHTML] Total lifecycle methods highlighted: ${lifecycle_count}`);
        }
        // Highlight convention methods (for all classes)
        console.log(`[JQHTML] Scanning for convention methods...`);
        let convention_count = 0;
        for (const method_name of CONVENTION_METHODS) {
            const regex = new RegExp(`\\b(static\\s+)?(async\\s+)?(${method_name})\\s*\\(`, 'g');
            let match;
            while ((match = regex.exec(text)) !== null) {
                const prefix_length = (match[1] ? match[1].length : 0) + (match[2] ? match[2].length : 0);
                const method_start = match.index + prefix_length;
                const position = document.positionAt(method_start);
                // Skip if this is inside a comment
                if (is_in_comment(document, position)) {
                    console.log(`[JQHTML] Skipping ${method_name} - inside comment`);
                    continue;
                }
                console.log(`[JQHTML] Found convention method: ${method_name} at line ${position.line}`);
                tokens_builder.push(position.line, position.character, method_name.length, 0, 0);
                convention_count++;
            }
        }
        console.log(`[JQHTML] Total convention methods highlighted: ${convention_count}`);
        // Highlight @Instantiatable in JSDoc comments
        console.log(`[JQHTML] Scanning for @Instantiatable annotations...`);
        let instantiatable_count = 0;
        const instantiatable_regex = /@(Instantiatable)\b/g;
        let instantiatable_match;
        while ((instantiatable_match = instantiatable_regex.exec(text)) !== null) {
            const annotation_start = instantiatable_match.index + 1; // Skip the @ symbol
            const position = document.positionAt(annotation_start);
            console.log(`[JQHTML] Found @Instantiatable at line ${position.line}`);
            tokens_builder.push(position.line, position.character, 'Instantiatable'.length, 0, 0);
            instantiatable_count++;
        }
        console.log(`[JQHTML] Total @Instantiatable annotations highlighted: ${instantiatable_count}`);
        const result = tokens_builder.build();
        console.log(`[JQHTML] Returning ${result.data.length} semantic tokens`);
        return result;
    }
}
exports.JqhtmlLifecycleSemanticTokensProvider = JqhtmlLifecycleSemanticTokensProvider;
/**
 * Provides hover information for JQHTML lifecycle methods
 */
class JqhtmlLifecycleHoverProvider {
    async provideHover(document, position) {
        const word_range = document.getWordRangeAtPosition(position);
        if (!word_range) {
            return undefined;
        }
        const word = document.getText(word_range);
        if (!JQHTML_LIFECYCLE_METHODS.includes(word)) {
            return undefined;
        }
        // Check if this is a method definition
        const line = document.lineAt(position.line).text;
        if (!line.includes('(')) {
            return undefined;
        }
        // Check if class extends Jqhtml_Component
        const text = document.getText();
        if (!has_extends_clause(text)) {
            return undefined;
        }
        const is_jqhtml = directly_extends_jqhtml(text);
        let extends_jqhtml = is_jqhtml;
        if (!is_jqhtml) {
            const class_name = extract_class_name(text);
            if (class_name) {
                extends_jqhtml = await is_subclass_of_jqhtml_component(class_name);
            }
        }
        if (!extends_jqhtml) {
            return undefined;
        }
        const markdown = new vscode.MarkdownString();
        markdown.isTrusted = true;
        const is_async = is_async_method(line);
        // Determine if async is required, forbidden, or optional
        const must_be_sync = ['on_create', 'on_render', 'on_stop'].includes(word);
        const must_be_async = word === 'on_load';
        const can_be_either = word === 'on_ready';
        let has_error = false;
        if (must_be_sync && is_async) {
            markdown.appendMarkdown(`**⚠️ Incorrect Async Declaration**\n\n`);
            markdown.appendMarkdown(`\`${word}\` must be synchronous - remove 'async' keyword.\n\n`);
            has_error = true;
        }
        else if (must_be_async && !is_async) {
            markdown.appendMarkdown(`**⚠️ Missing Async Declaration**\n\n`);
            markdown.appendMarkdown(`\`${word}\` must be async - add 'async' keyword.\n\n`);
            has_error = true;
        }
        if (!has_error) {
            markdown.appendMarkdown(`**JQHTML Lifecycle Method**\n\n`);
        }
        markdown.appendMarkdown(`${LIFECYCLE_DOCS[word]}\n\n`);
        markdown.appendMarkdown(`Run \`php artisan rsx:man jqhtml\` for more information.`);
        return new vscode.Hover(markdown, word_range);
    }
}
exports.JqhtmlLifecycleHoverProvider = JqhtmlLifecycleHoverProvider;
/**
 * Diagnostic provider for non-async lifecycle methods
 */
class JqhtmlLifecycleDiagnosticProvider {
    constructor() {
        this.document_cache = new Map();
        this.diagnostics_collection = vscode.languages.createDiagnosticCollection('rspade-jqhtml');
    }
    activate(context) {
        context.subscriptions.push(vscode.workspace.onDidChangeTextDocument((event) => {
            this.update_diagnostics(event.document);
        }));
        vscode.workspace.textDocuments.forEach((document) => {
            this.update_diagnostics(document);
        });
        context.subscriptions.push(vscode.workspace.onDidOpenTextDocument((document) => {
            this.update_diagnostics(document);
        }));
        context.subscriptions.push(vscode.workspace.onDidSaveTextDocument((document) => {
            // Clear cache on save to force lineage re-check
            this.document_cache.delete(document.uri.toString());
            this.update_diagnostics(document);
        }));
    }
    async update_diagnostics(document) {
        if (document.languageId !== 'javascript' && document.languageId !== 'typescript') {
            return;
        }
        const text = document.getText();
        if (!has_extends_clause(text)) {
            return;
        }
        // Check cache
        const cache_key = document.uri.toString();
        let extends_jqhtml = this.document_cache.get(cache_key);
        if (extends_jqhtml === undefined) {
            const is_jqhtml = directly_extends_jqhtml(text);
            extends_jqhtml = is_jqhtml;
            if (!is_jqhtml) {
                const class_name = extract_class_name(text);
                if (class_name) {
                    extends_jqhtml = await is_subclass_of_jqhtml_component(class_name);
                }
            }
            this.document_cache.set(cache_key, extends_jqhtml);
        }
        if (!extends_jqhtml) {
            return;
        }
        const diagnostics = [];
        const lines = text.split('\n');
        // Find each lifecycle method and validate
        for (const method_name of JQHTML_LIFECYCLE_METHODS) {
            // Find method definition and extract its body
            const method_regex = new RegExp(`^\\s*(static\\s+)?(async\\s+)?(${method_name})\\s*\\([^)]*\\)\\s*\\{`, 'gm');
            let method_match;
            while ((method_match = method_regex.exec(text)) !== null) {
                const is_static = !!method_match[1];
                const is_async = !!method_match[2];
                const method_start_index = method_match.index + method_match[0].indexOf(method_name);
                const method_start_pos = document.positionAt(method_start_index);
                const method_end_pos = document.positionAt(method_start_index + method_name.length);
                const method_name_range = new vscode.Range(method_start_pos, method_end_pos);
                // Check if method is static (should not be)
                if (is_static) {
                    diagnostics.push(new vscode.Diagnostic(method_name_range, `JQHTML lifecycle method '${method_name}' should not be static`, vscode.DiagnosticSeverity.Warning));
                }
                // Check async requirements
                if (method_name === 'on_create' && is_async) {
                    diagnostics.push(new vscode.Diagnostic(method_name_range, `'on_create' must be synchronous - remove 'async' keyword`, vscode.DiagnosticSeverity.Error));
                }
                else if (method_name === 'on_render' && is_async) {
                    diagnostics.push(new vscode.Diagnostic(method_name_range, `'on_render' must be synchronous - remove 'async' keyword`, vscode.DiagnosticSeverity.Error));
                }
                else if (method_name === 'on_stop' && is_async) {
                    diagnostics.push(new vscode.Diagnostic(method_name_range, `'on_stop' must be synchronous - remove 'async' keyword`, vscode.DiagnosticSeverity.Error));
                }
                else if (method_name === 'on_load' && !is_async) {
                    diagnostics.push(new vscode.Diagnostic(method_name_range, `'on_load' must be async - add 'async' keyword`, vscode.DiagnosticSeverity.Error));
                }
                // on_ready can be either async or non-async - no requirement
                // Find method body to check for violations
                const method_body_start = method_match.index + method_match[0].length;
                let brace_count = 1;
                let body_end = method_body_start;
                for (let i = method_body_start; i < text.length && brace_count > 0; i++) {
                    if (text[i] === '{')
                        brace_count++;
                    if (text[i] === '}')
                        brace_count--;
                    if (brace_count === 0) {
                        body_end = i;
                        break;
                    }
                }
                const method_body = text.substring(method_body_start, body_end);
                // Check for violations in method body
                if (method_name === 'on_create') {
                    // Check for this.data or that.data access (reading, not assignment)
                    // Note: on_create() now runs BEFORE first render, so assigning to this.data is valid
                    // We only warn on reading from this.data (accessing properties without assignment)
                    const data_access_regex = /(this|that)\.data\.(\w+)(\s*=)?/g;
                    let data_match;
                    while ((data_match = data_access_regex.exec(method_body)) !== null) {
                        // Skip if this is an assignment (has the = part in capture group 3)
                        if (data_match[3]) {
                            continue;
                        }
                        // This is a read access, not an assignment - warn about it
                        const violation_pos = document.positionAt(method_body_start + data_match.index);
                        const violation_end = document.positionAt(method_body_start + data_match.index + data_match[0].length);
                        diagnostics.push(new vscode.Diagnostic(new vscode.Range(violation_pos, violation_end), `'${data_match[0]}' is being read in on_create, but this.data should only be initialized here (assignments like 'this.data.rows = []' are OK)`, vscode.DiagnosticSeverity.Warning));
                    }
                }
                if (method_name === 'on_load') {
                    // Check for DOM access: this.$, this.$id, that.$, that.$id
                    const dom_access_regex = /(this|that)\.\$(?:id)?/g;
                    let dom_match;
                    while ((dom_match = dom_access_regex.exec(method_body)) !== null) {
                        const violation_pos = document.positionAt(method_body_start + dom_match.index);
                        const violation_end = document.positionAt(method_body_start + dom_match.index + dom_match[0].length);
                        diagnostics.push(new vscode.Diagnostic(new vscode.Range(violation_pos, violation_end), `'on_load' should not access DOM elements. It should be headless, using only ${dom_match[1]}.args for inputs and setting ${dom_match[1]}.data for outputs`, vscode.DiagnosticSeverity.Warning));
                    }
                }
            }
        }
        this.diagnostics_collection.set(document.uri, diagnostics);
    }
    dispose() {
        this.diagnostics_collection.dispose();
    }
}
exports.JqhtmlLifecycleDiagnosticProvider = JqhtmlLifecycleDiagnosticProvider;
//# sourceMappingURL=jqhtml_lifecycle_provider.js.map