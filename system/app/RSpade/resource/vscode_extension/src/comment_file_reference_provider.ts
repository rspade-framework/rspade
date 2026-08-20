import * as vscode from 'vscode';
import * as path from 'path';
import * as fs from 'fs';

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

    // Check for block comment markers
    const doc_comment_idx = line_text.indexOf('/*');
    const doc_comment_end_idx = line_text.indexOf('*/');
    const asterisk_comment = line_text.trim().startsWith('*');

    if (asterisk_comment || doc_comment_idx !== -1 || doc_comment_end_idx !== -1) {
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
 * Get the word at position, including periods
 */
function get_word_with_period(document: vscode.TextDocument, position: vscode.Position): { word: string, range: vscode.Range } | undefined {
    const line = document.lineAt(position.line);
    const line_text = line.text;
    const char = position.character;

    // Find start of word (alphanumeric, underscore, period, hyphen)
    let start = char;
    while (start > 0 && /[a-zA-Z0-9_.-]/.test(line_text[start - 1])) {
        start--;
    }

    // Find end of word
    let end = char;
    while (end < line_text.length && /[a-zA-Z0-9_.-]/.test(line_text[end])) {
        end++;
    }

    const word = line_text.substring(start, end);

    // Must contain a period and not be just a period
    if (!word.includes('.') || word === '.') {
        return undefined;
    }

    const range = new vscode.Range(
        new vscode.Position(position.line, start),
        new vscode.Position(position.line, end)
    );

    return { word, range };
}

/**
 * Check if a file exists in the same directory as the document
 */
function find_file_in_same_directory(document: vscode.TextDocument, filename: string): string | undefined {
    const doc_dir = path.dirname(document.uri.fsPath);
    const file_path = path.join(doc_dir, filename);

    if (fs.existsSync(file_path)) {
        return file_path;
    }

    return undefined;
}

/**
 * Provides semantic tokens for file references in comments (light blue like class properties)
 */
export class CommentFileReferenceSemanticTokensProvider implements vscode.DocumentSemanticTokensProvider {
    async provideDocumentSemanticTokens(document: vscode.TextDocument): Promise<vscode.SemanticTokens> {
        const tokens_builder = new vscode.SemanticTokensBuilder();

        // Only for JavaScript/TypeScript files
        if (document.languageId !== 'javascript' && document.languageId !== 'typescript') {
            return tokens_builder.build();
        }

        const text = document.getText();

        // Find all words with periods
        const regex = /\b[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+\b/g;
        let match;

        while ((match = regex.exec(text)) !== null) {
            const start_pos = document.positionAt(match.index);

            // Skip if not in a comment
            if (!is_in_comment(document, start_pos)) {
                continue;
            }

            const word = match[0];

            // Check if file exists in same directory
            if (find_file_in_same_directory(document, word)) {
                tokens_builder.push(
                    start_pos.line,
                    start_pos.character,
                    word.length,
                    0, // token type index for 'class' (teal)
                    0  // token modifiers
                );
            }
        }

        return tokens_builder.build();
    }
}

/**
 * Provides "Go to Definition" for file references in comments
 */
export class CommentFileReferenceDefinitionProvider implements vscode.DefinitionProvider {
    async provideDefinition(
        document: vscode.TextDocument,
        position: vscode.Position,
        _token: vscode.CancellationToken
    ): Promise<vscode.Definition | undefined> {
        // Only for JavaScript/TypeScript files
        if (document.languageId !== 'javascript' && document.languageId !== 'typescript') {
            return undefined;
        }

        // Must be in a comment
        if (!is_in_comment(document, position)) {
            return undefined;
        }

        // Get word with period
        const word_info = get_word_with_period(document, position);
        if (!word_info) {
            return undefined;
        }

        // Check if file exists in same directory
        const file_path = find_file_in_same_directory(document, word_info.word);
        if (!file_path) {
            return undefined;
        }

        // Return file location
        const file_uri = vscode.Uri.file(file_path);
        return new vscode.Location(file_uri, new vscode.Position(0, 0));
    }
}
