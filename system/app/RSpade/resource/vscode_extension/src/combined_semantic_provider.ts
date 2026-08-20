import * as vscode from 'vscode';
import { JqhtmlLifecycleSemanticTokensProvider } from './jqhtml_lifecycle_provider';
import { CommentFileReferenceSemanticTokensProvider } from './comment_file_reference_provider';
import { ThatVariableSemanticTokensProvider } from './that_variable_provider';

interface DecodedToken {
    line: number;
    char: number;
    length: number;
    type: number;
    modifiers: number;
}

/**
 * Combined semantic tokens provider that merges tokens from multiple providers
 *
 * VS Code only allows one SemanticTokensLegend per language, so we need to
 * combine all our providers into one to avoid conflicts.
 */
export class CombinedSemanticTokensProvider implements vscode.DocumentSemanticTokensProvider {
    private jqhtml_provider: JqhtmlLifecycleSemanticTokensProvider;
    private file_ref_provider: CommentFileReferenceSemanticTokensProvider;
    private that_provider: ThatVariableSemanticTokensProvider;

    constructor() {
        this.jqhtml_provider = new JqhtmlLifecycleSemanticTokensProvider();
        this.file_ref_provider = new CommentFileReferenceSemanticTokensProvider();
        this.that_provider = new ThatVariableSemanticTokensProvider();
    }

    async provideDocumentSemanticTokens(document: vscode.TextDocument): Promise<vscode.SemanticTokens> {
        // Get tokens from all providers
        const jqhtml_tokens = await this.jqhtml_provider.provideDocumentSemanticTokens(document);
        const file_ref_tokens = await this.file_ref_provider.provideDocumentSemanticTokens(document);
        const that_tokens = await this.that_provider.provideDocumentSemanticTokens(document);

        // Decode all tokens to absolute positions
        const decoded_tokens: DecodedToken[] = [];

        // Decode JQHTML tokens (type 0 = conventionMethod, orange)
        this.decode_tokens(jqhtml_tokens.data, 0, decoded_tokens);

        // Decode file reference tokens (type 1 = class, teal)
        this.decode_tokens(file_ref_tokens.data, 1, decoded_tokens);

        // Decode 'that' tokens (type 2 = macro, dark blue #569CD6 like 'this')
        this.decode_tokens(that_tokens.data, 2, decoded_tokens);

        // Sort tokens by line, then by character
        decoded_tokens.sort((a, b) => {
            if (a.line !== b.line) {
                return a.line - b.line;
            }
            return a.char - b.char;
        });

        // Re-encode tokens with delta encoding
        const builder = new vscode.SemanticTokensBuilder();
        for (const token of decoded_tokens) {
            builder.push(token.line, token.char, token.length, token.type, token.modifiers);
        }

        return builder.build();
    }

    private decode_tokens(data: Uint32Array, new_type: number, output: DecodedToken[]): void {
        let current_line = 0;
        let current_char = 0;

        for (let i = 0; i < data.length; i += 5) {
            const delta_line = data[i];
            const delta_char = data[i + 1];
            const length = data[i + 2];
            const modifiers = data[i + 4];

            if (delta_line > 0) {
                current_line += delta_line;
                current_char = delta_char;
            } else {
                current_char += delta_char;
            }

            output.push({
                line: current_line,
                char: current_char,
                length: length,
                type: new_type,
                modifiers: modifiers
            });
        }
    }

    private decode_tokens_with_modifier(data: Uint32Array, new_type: number, new_modifier: number, output: DecodedToken[]): void {
        let current_line = 0;
        let current_char = 0;

        for (let i = 0; i < data.length; i += 5) {
            const delta_line = data[i];
            const delta_char = data[i + 1];
            const length = data[i + 2];

            if (delta_line > 0) {
                current_line += delta_line;
                current_char = delta_char;
            } else {
                current_char += delta_char;
            }

            output.push({
                line: current_line,
                char: current_char,
                length: length,
                type: new_type,
                modifiers: new_modifier
            });
        }
    }
}
