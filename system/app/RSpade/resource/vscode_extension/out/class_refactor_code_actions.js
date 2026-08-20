"use strict";
/**
 * RSpade Class Refactor Code Actions Provider
 *
 * Provides refactoring actions that appear in the "Refactor..." menu
 * when the cursor is on a class definition line.
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
exports.RspadeClassRefactorCodeActionsProvider = void 0;
const vscode = __importStar(require("vscode"));
class RspadeClassRefactorCodeActionsProvider {
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
        // Check if line contains a class definition at indent level 0
        const position = range.start;
        const line = document.lineAt(position.line).text;
        // Must be class definition at start of line (indent level 0)
        const class_definition_match = line.match(/^(?:abstract\s+|final\s+)?class\s+([A-Z][a-zA-Z0-9_]*)/);
        if (class_definition_match) {
            return this.create_refactor_actions();
        }
        return undefined;
    }
    create_refactor_actions() {
        const action = new vscode.CodeAction('Global Rename Class', vscode.CodeActionKind.Refactor);
        action.command = {
            command: 'rspade.refactorClass',
            title: 'Global Rename Class'
        };
        return [action];
    }
}
exports.RspadeClassRefactorCodeActionsProvider = RspadeClassRefactorCodeActionsProvider;
//# sourceMappingURL=class_refactor_code_actions.js.map