import * as vscode from 'vscode';

/**
 * Convention methods that are called automatically by the RSX framework
 * These methods are invoked by name at runtime, not through direct references
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
 * Check if a method is static by examining the line text
 */
function is_static_method(line_text: string): boolean {
    return line_text.trim().startsWith('static ');
}

/**
 * Check if position is inside a comment
 */
function is_in_comment(document: vscode.TextDocument, position: vscode.Position): boolean {
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
        } else if (text_before.substring(i, i + 2) === '*/') {
            in_block_comment = false;
            i += 2;
        } else {
            i++;
        }
    }

    return in_block_comment;
}

/**
 * Provides semantic tokens for convention methods (amber color)
 */
export class ConventionMethodSemanticTokensProvider implements vscode.DocumentSemanticTokensProvider {
    async provideDocumentSemanticTokens(document: vscode.TextDocument): Promise<vscode.SemanticTokens> {
        const tokens_builder = new vscode.SemanticTokensBuilder();

        if (document.languageId !== 'javascript' && document.languageId !== 'typescript') {
            return tokens_builder.build();
        }

        const text = document.getText();

        // Find all static method definitions matching convention methods
        for (const method_name of CONVENTION_METHODS) {
            // Match: static method_name(...)
            const regex = new RegExp(`\\bstatic\\s+(${method_name})\\s*\\(`, 'g');
            let match;

            while ((match = regex.exec(text)) !== null) {
                const method_start = match.index + 'static '.length;
                const position = document.positionAt(method_start);

                // Skip if this is inside a comment
                if (is_in_comment(document, position)) {
                    continue;
                }

                tokens_builder.push(
                    position.line,
                    position.character,
                    method_name.length,
                    0, // token type index for 'conventionMethod'
                    0  // token modifiers
                );
            }
        }

        return tokens_builder.build();
    }
}

/**
 * Provides hover information for convention methods
 */
export class ConventionMethodHoverProvider implements vscode.HoverProvider {
    provideHover(document: vscode.TextDocument, position: vscode.Position): vscode.Hover | undefined {
        const word_range = document.getWordRangeAtPosition(position);
        if (!word_range) {
            return undefined;
        }

        const word = document.getText(word_range);

        if (!CONVENTION_METHODS.includes(word)) {
            return undefined;
        }

        // Check if this is a method definition
        const line = document.lineAt(position.line).text;
        if (!line.includes('(')) {
            return undefined;
        }

        const markdown = new vscode.MarkdownString();
        markdown.isTrusted = true;

        if (is_static_method(line)) {
            markdown.appendMarkdown(`**Convention Method**\n\n`);
            markdown.appendMarkdown(`This method is automatically called by \`Rsx.js\` during initialization of the client-side RSpade runtime.\n\n`);
            markdown.appendMarkdown(`Convention methods are invoked by name and do not appear as direct references in the codebase.`);
        } else {
            markdown.appendMarkdown(`**⚠️ Non-Static Convention Method**\n\n`);
            markdown.appendMarkdown(`This method name is reserved for framework convention methods, but it is not declared as \`static\`.\n\n`);
            markdown.appendMarkdown(`Convention methods must be \`static\` to be called by the RSX framework.`);
        }

        return new vscode.Hover(markdown, word_range);
    }
}

/**
 * Provides diagnostics for non-static convention methods
 */
export class ConventionMethodDiagnosticProvider {
    private diagnostics_collection: vscode.DiagnosticCollection;

    constructor() {
        this.diagnostics_collection = vscode.languages.createDiagnosticCollection('rspade-convention');
    }

    activate(context: vscode.ExtensionContext) {
        // Update diagnostics on document change
        context.subscriptions.push(
            vscode.workspace.onDidChangeTextDocument((event) => {
                this.update_diagnostics(event.document);
            })
        );

        // Update diagnostics for all open documents
        vscode.workspace.textDocuments.forEach(document => {
            this.update_diagnostics(document);
        });

        // Update diagnostics when opening a document
        context.subscriptions.push(
            vscode.workspace.onDidOpenTextDocument(document => {
                this.update_diagnostics(document);
            })
        );
    }

    private update_diagnostics(document: vscode.TextDocument) {
        if (document.languageId !== 'javascript' && document.languageId !== 'typescript') {
            return;
        }

        const diagnostics: vscode.Diagnostic[] = [];
        const text = document.getText();

        for (const method_name of CONVENTION_METHODS) {
            // Match non-static methods with convention names: method_name(...) without 'static' before it
            const regex = new RegExp(`^\\s*(?!static\\s)(${method_name})\\s*\\(`, 'gm');
            let match;

            while ((match = regex.exec(text)) !== null) {
                const method_start = match.index + match[0].indexOf(method_name);
                const start_pos = document.positionAt(method_start);
                const end_pos = document.positionAt(method_start + method_name.length);
                const range = new vscode.Range(start_pos, end_pos);

                const diagnostic = new vscode.Diagnostic(
                    range,
                    `Convention method '${method_name}' must be declared as 'static' to be called by the RSX framework`,
                    vscode.DiagnosticSeverity.Error
                );

                diagnostic.source = 'RSpade';
                diagnostics.push(diagnostic);
            }
        }

        this.diagnostics_collection.set(document.uri, diagnostics);
    }

    dispose() {
        this.diagnostics_collection.dispose();
    }
}

/**
 * Provides go-to-definition for convention methods
 */
export class ConventionMethodDefinitionProvider implements vscode.DefinitionProvider {
    async provideDefinition(
        document: vscode.TextDocument,
        position: vscode.Position
    ): Promise<vscode.Location | undefined> {
        const word_range = document.getWordRangeAtPosition(position);
        if (!word_range) {
            return undefined;
        }

        const word = document.getText(word_range);

        if (!CONVENTION_METHODS.includes(word)) {
            return undefined;
        }

        // Check if this is a method definition
        const line = document.lineAt(position.line).text;
        if (!line.includes('(') || !is_static_method(line)) {
            return undefined;
        }

        // Find Rsx.js in the workspace
        const files = await vscode.workspace.findFiles('**/Rsx.js', '**/node_modules/**');
        if (files.length === 0) {
            return undefined;
        }

        // Use the first match (should only be one Rsx.js)
        const rsx_file = files[0];
        const rsx_document = await vscode.workspace.openTextDocument(rsx_file);
        const rsx_text = rsx_document.getText();

        // Find _rsx_core_boot method
        const boot_regex = /static\s+async\s+_rsx_core_boot\s*\(/;
        const match = boot_regex.exec(rsx_text);

        if (!match) {
            return undefined;
        }

        const boot_position = rsx_document.positionAt(match.index);
        return new vscode.Location(rsx_file, boot_position);
    }
}
