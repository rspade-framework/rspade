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
exports.PhpAttributeSemanticTokensProvider = void 0;
const vscode = __importStar(require("vscode"));
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
class PhpAttributeSemanticTokensProvider {
    async provideDocumentSemanticTokens(document) {
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
                tokens_builder.push(position.line, position.character, attribute_name.length, 0, // token type index for 'conventionMethod'
                0 // token modifiers
                );
            }
        }
        return tokens_builder.build();
    }
}
exports.PhpAttributeSemanticTokensProvider = PhpAttributeSemanticTokensProvider;
//# sourceMappingURL=php_attribute_provider.js.map