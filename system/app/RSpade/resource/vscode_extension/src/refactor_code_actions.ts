/**
 * RSpade Refactor Code Actions Provider
 *
 * Provides refactoring actions that appear in the "Refactor..." menu
 * when the cursor is on a static method definition or call.
 */

import * as vscode from 'vscode';
import { RspadeRefactorProvider } from './refactor_provider';

export class RspadeRefactorCodeActionsProvider implements vscode.CodeActionProvider {
    private refactor_provider: RspadeRefactorProvider;

    constructor(refactor_provider: RspadeRefactorProvider) {
        this.refactor_provider = refactor_provider;
    }

    public provideCodeActions(
        document: vscode.TextDocument,
        range: vscode.Range | vscode.Selection,
        context: vscode.CodeActionContext,
        token: vscode.CancellationToken
    ): vscode.CodeAction[] | undefined {
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

    private create_refactor_actions(): vscode.CodeAction[] {
        const action = new vscode.CodeAction(
            'Global Rename Method',
            vscode.CodeActionKind.Refactor
        );
        action.command = {
            command: 'rspade.refactorStaticMethod',
            title: 'Global Rename Method'
        };
        return [action];
    }
}
