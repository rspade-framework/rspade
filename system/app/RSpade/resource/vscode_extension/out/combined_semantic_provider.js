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
exports.CombinedSemanticTokensProvider = void 0;
const vscode = __importStar(require("vscode"));
const jqhtml_lifecycle_provider_1 = require("./jqhtml_lifecycle_provider");
const comment_file_reference_provider_1 = require("./comment_file_reference_provider");
const that_variable_provider_1 = require("./that_variable_provider");
/**
 * Combined semantic tokens provider that merges tokens from multiple providers
 *
 * VS Code only allows one SemanticTokensLegend per language, so we need to
 * combine all our providers into one to avoid conflicts.
 */
class CombinedSemanticTokensProvider {
    constructor() {
        this.jqhtml_provider = new jqhtml_lifecycle_provider_1.JqhtmlLifecycleSemanticTokensProvider();
        this.file_ref_provider = new comment_file_reference_provider_1.CommentFileReferenceSemanticTokensProvider();
        this.that_provider = new that_variable_provider_1.ThatVariableSemanticTokensProvider();
    }
    async provideDocumentSemanticTokens(document) {
        // Get tokens from all providers
        const jqhtml_tokens = await this.jqhtml_provider.provideDocumentSemanticTokens(document);
        const file_ref_tokens = await this.file_ref_provider.provideDocumentSemanticTokens(document);
        const that_tokens = await this.that_provider.provideDocumentSemanticTokens(document);
        // Decode all tokens to absolute positions
        const decoded_tokens = [];
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
    decode_tokens(data, new_type, output) {
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
            }
            else {
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
    decode_tokens_with_modifier(data, new_type, new_modifier, output) {
        let current_line = 0;
        let current_char = 0;
        for (let i = 0; i < data.length; i += 5) {
            const delta_line = data[i];
            const delta_char = data[i + 1];
            const length = data[i + 2];
            if (delta_line > 0) {
                current_line += delta_line;
                current_char = delta_char;
            }
            else {
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
exports.CombinedSemanticTokensProvider = CombinedSemanticTokensProvider;
//# sourceMappingURL=combined_semantic_provider.js.map