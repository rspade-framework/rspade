"use strict";
/**
 * RSpade Refactor Code Actions Provider
 *
 * Provides refactoring actions that appear in the "Refactor..." menu
 * when the cursor is on a static method definition or call.
 */
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
exports.RspadeRefactorCodeActionsProvider = void 0;
const vscode = __importStar(require("vscode"));
class RspadeRefactorCodeActionsProvider {
    constructor(refactor_provider) {
        this.refactor_provider = refactor_provider;
    }
    provideCodeActions(document, range, context, token) {
        // Only provide actions for PHP files in ./rsx or ./app/RSpade
        if (document.languageId !== 'php') {
            return undefined;
        }
        const file_path = document.uri.fsPath;
        if (!file_path.includes('/rsx/') && !file_path.includes('\\rsx\\') &&
            !file_path.includes('/app/RSpade') && !file_path.includes('\\app\\RSpade')) {
            return undefined;
        }
        // Check if line contains a static method (cursor can be anywhere on the line)
        const position = range.start;
        const line = document.lineAt(position.line).text;
        // Check for static method definition: public/protected/private static function method_name
        const static_definition_match = line.match(/\b(?:public|protected|private)\s+static\s+function\s+(\w+)/);
        if (static_definition_match) {
            return this.create_refactor_actions();
        }
        // Check for static method call: ClassName::method_name
        const static_call_match = line.match(/(\w+)::(\w+)/);
        if (static_call_match) {
            return this.create_refactor_actions();
        }
        return undefined;
    }
    create_refactor_actions() {
        const action = new vscode.CodeAction('Global Rename Method', vscode.CodeActionKind.Refactor);
        action.command = {
            command: 'rspade.refactorStaticMethod',
            title: 'Global Rename Method'
        };
        return [action];
    }
}
exports.RspadeRefactorCodeActionsProvider = RspadeRefactorCodeActionsProvider;
//# sourceMappingURL=refactor_code_actions.js.map