/**
 * RSpade Sort Class Methods Provider
 *
 * Reorganizes methods in PHP class files according to RSpade conventions
 */

import * as vscode from 'vscode';
import * as path from 'path';
import { RspadeFormattingProvider } from './formatting_provider';

export class RspadeSortClassMethodsProvider {
    private formatting_provider: RspadeFormattingProvider;
    private output_channel: vscode.OutputChannel;

    constructor(formatting_provider: RspadeFormattingProvider) {
        this.formatting_provider = formatting_provider;
        this.output_channel = vscode.window.createOutputChannel('RSpade Sort Methods');
    }

    /**
     * Register the sort command
     */
    public register(context: vscode.ExtensionContext): void {
        const command = vscode.commands.registerCommand(
            'rspade.sortClassMethods',
            async (uri?: vscode.Uri) => await this.sort_class_methods(uri)
        );
        context.subscriptions.push(command);
    }

    /**
     * Main sort method
     */
    private async sort_class_methods(uri?: vscode.Uri): Promise<void> {
        // Determine file path
        let file_path: string;

        if (uri) {
            // Called from explorer context menu
            file_path = uri.fsPath;
        } else {
            // Called from command palette - use active editor
            const editor = vscode.window.activeTextEditor;
            if (!editor) {
                vscode.window.showErrorMessage('No active file to sort');
                return;
            }
            file_path = editor.document.uri.fsPath;
        }

        // Validate it's a PHP file
        if (!file_path.endsWith('.php')) {
            vscode.window.showErrorMessage('Can only sort PHP class files');
            return;
        }

        this.output_channel.clear();
        this.output_channel.show(true);
        this.output_channel.appendLine('=== RSpade Sort Class Methods ===\n');
        this.output_channel.appendLine(`File: ${file_path}\n`);

        try {
            // Get workspace root to make path relative
            const workspace_folder = vscode.workspace.getWorkspaceFolder(vscode.Uri.file(file_path));
            if (!workspace_folder) {
                throw new Error('File is not in workspace');
            }

            const relative_path = path.relative(workspace_folder.uri.fsPath, file_path);
            this.output_channel.appendLine(`Relative path: ${relative_path}\n`);

            // Confirm sorting
            const confirmation = await vscode.window.showWarningMessage(
                `Sort methods in ${path.basename(file_path)}?\n\n` +
                'This will reorganize all methods according to RSpade conventions.',
                { modal: true },
                'Sort',
                'Cancel'
            );

            if (confirmation !== 'Sort') {
                this.output_channel.appendLine('Sort cancelled by user');
                await vscode.commands.executeCommand('workbench.action.closePanel');
                return;
            }

            // Execute sort
            this.output_channel.appendLine('Sorting methods...\n');
            const result = await this.execute_sort(relative_path);

            // Display result
            this.output_channel.appendLine('\n=== Sort Output ===\n');
            this.output_channel.appendLine(result);
            this.output_channel.appendLine('\n=== Sort Complete ===');

            // Reload the file if it's open
            const document = await vscode.workspace.openTextDocument(file_path);
            const editors = vscode.window.visibleTextEditors.filter(
                editor => editor.document.uri.fsPath === file_path
            );

            if (editors.length > 0) {
                // Wait 3.5 seconds, close panel, then reload
                setTimeout(async () => {
                    await vscode.commands.executeCommand('workbench.action.closePanel');

                    // Wait 500ms for filesystem changes
                    setTimeout(async () => {
                        for (const editor of editors) {
                            const position = editor.selection.active;
                            const view_column = editor.viewColumn;

                            await vscode.commands.executeCommand('workbench.action.closeActiveEditor');
                            const new_document = await vscode.workspace.openTextDocument(file_path);
                            const new_editor = await vscode.window.showTextDocument(new_document, view_column);

                            // Restore cursor position
                            new_editor.selection = new vscode.Selection(position, position);
                            new_editor.revealRange(new vscode.Range(position, position));
                        }
                    }, 500);
                }, 3500);
            } else {
                // File not open, just close panel
                setTimeout(async () => {
                    await vscode.commands.executeCommand('workbench.action.closePanel');
                }, 3500);
            }

            vscode.window.showInformationMessage(`Successfully sorted methods in ${path.basename(file_path)}`);

        } catch (error: any) {
            const error_message = error.message || String(error);
            this.output_channel.appendLine(`\nERROR: ${error_message}`);
            vscode.window.showErrorMessage(`Sort failed: ${error_message}`);
        }
    }

    /**
     * Execute the sort command via IDE service
     */
    private async execute_sort(file_path: string): Promise<string> {
        // Prepare request data
        const request_data = {
            command: 'rsx:refactor:sort_php_class_functions',
            arguments: [file_path]
        };

        this.output_channel.appendLine('Sending sort request to server...');
        this.output_channel.appendLine(`Command: ${request_data.command}`);
        this.output_channel.appendLine(`Arguments: ${JSON.stringify(request_data.arguments)}\n`);

        // Grant-authenticated request (X-Ide-Token)
        const response = await this.formatting_provider.ide_service_request(
            '/refactor',
            request_data
        );

        if (!response.success) {
            throw new Error(response.error || 'Sort command failed');
        }

        return response.output || 'Sort completed successfully (no output)';
    }
}
