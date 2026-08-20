import * as vscode from 'vscode';

/**
 * Check if position is inside a string
 */
function is_in_string(document: vscode.TextDocument, position: vscode.Position): boolean {
    const line_text = document.lineAt(position.line).text;
    const char_pos = position.character;

    // Simple check: count quotes before position
    let single_quotes = 0;
    let double_quotes = 0;

    for (let i = 0; i < char_pos; i++) {
        if (line_text[i] === "'" && (i === 0 || line_text[i - 1] !== '\\')) {
            single_quotes++;
        } else if (line_text[i] === '"' && (i === 0 || line_text[i - 1] !== '\\')) {
            double_quotes++;
        }
    }

    // If odd number of quotes, we're inside a string
    return (single_quotes % 2 === 1) || (double_quotes % 2 === 1);
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
 * Provides semantic tokens for 'that' variable (dark blue like 'this' keyword)
 */
export class ThatVariableSemanticTokensProvider implements vscode.DocumentSemanticTokensProvider {
    async provideDocumentSemanticTokens(document: vscode.TextDocument): Promise<vscode.SemanticTokens> {
        const tokens_builder = new vscode.SemanticTokensBuilder();

        // Only for JavaScript/TypeScript files
        if (document.languageId !== 'javascript' && document.languageId !== 'typescript') {
            return tokens_builder.build();
        }

        const text = document.getText();

        // Find all occurrences of 'that' as a standalone word
        const regex = /\bthat\b/g;
        let match;

        while ((match = regex.exec(text)) !== null) {
            const start_pos = document.positionAt(match.index);

            // Skip if inside a string
            if (is_in_string(document, start_pos)) {
                continue;
            }

            // Skip if inside a comment
            if (is_in_comment(document, start_pos)) {
                continue;
            }

            // Highlight 'that' with same blue color as 'this' keyword
            // Using 'variable' type with 'defaultLibrary' modifier to match language variable color
            tokens_builder.push(
                start_pos.line,
                start_pos.character,
                4, // length of 'that'
                0, // token type index for 'variable'
                1  // token modifiers: bit 0 = defaultLibrary
            );
        }

        return tokens_builder.build();
    }
}
