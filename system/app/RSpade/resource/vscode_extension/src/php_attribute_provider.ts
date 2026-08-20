import * as vscode from 'vscode';

/**
 * Framework PHP attributes that should be highlighted
 */
const FRAMEWORK_ATTRIBUTES = [
    'Ajax_Endpoint',
    'Route',
    'Auth',
    'Task',
    'Relationship',
    'Monoprogenic',
    'Instantiatable'
];

/**
 * Provides semantic tokens for PHP attributes (amber color)
 */
export class PhpAttributeSemanticTokensProvider implements vscode.DocumentSemanticTokensProvider {
    async provideDocumentSemanticTokens(document: vscode.TextDocument): Promise<vscode.SemanticTokens> {
        const tokens_builder = new vscode.SemanticTokensBuilder();

        if (document.languageId !== 'php') {
            return tokens_builder.build();
        }

        const text = document.getText();

        // Find all PHP attributes: #[AttributeName] or #[\AttributeName]
        for (const attribute_name of FRAMEWORK_ATTRIBUTES) {
            // Match: #[AttributeName or #[\AttributeName with optional namespace prefix
            // Captures the attribute name only (not brackets or backslash)
            const regex = new RegExp(`#\\[\\\\?(${attribute_name})(?:\\s|\\(|\\])`, 'g');
            let match;

            while ((match = regex.exec(text)) !== null) {
                // match[1] contains the attribute name without namespace prefix
                const attr_start = match.index + match[0].indexOf(match[1]);
                const position = document.positionAt(attr_start);

                tokens_builder.push(
                    position.line,
                    position.character,
                    attribute_name.length,
                    0, // token type index for 'conventionMethod'
                    0  // token modifiers
                );
            }
        }

        return tokens_builder.build();
    }
}
