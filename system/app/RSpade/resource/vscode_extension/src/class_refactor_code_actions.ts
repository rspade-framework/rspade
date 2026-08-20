/**
 * RSpade Class Refactor Code Actions Provider
 *
 * Provides refactoring actions that appear in the "Refactor..." menu
 * when the cursor is on a class definition line.
 */

import * as vscode from 'vscode';
import { RspadeClassRefactorProvider } from './class_refactor_provider';

export class RspadeClassRefactorCodeActionsProvider implements vscode.CodeActionProvider {
    private refactor_provider: RspadeClassRefactorProvider;

    constructor(refactor_provider: RspadeClassRefactorProvider) {
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

    private create_refactor_actions(): vscode.CodeAction[] {
        const action = new vscode.CodeAction(
            'Global Rename Class',
            vscode.CodeActionKind.Refactor
        );
        action.command = {
            command: 'rspade.refactorClass',
            title: 'Global Rename Class'
        };
        return [action];
    }
}
