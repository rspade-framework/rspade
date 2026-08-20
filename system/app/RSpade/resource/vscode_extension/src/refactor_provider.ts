/**
 * RSpade Refactor Provider
 *
 * Provides context menu refactoring options for PHP static methods.
 * Communicates with the server-side refactor commands via the IDE service.
 */

import * as vscode from 'vscode';
import { RspadeFormattingProvider } from './formatting_provider';

export class RspadeRefactorProvider {
    private formatting_provider: RspadeFormattingProvider;
    private output_channel: vscode.OutputChannel;

    constructor(formatting_provider: RspadeFormattingProvider) {
        this.formatting_provider = formatting_provider;
        this.output_channel = vscode.window.createOutputChannel('RSpade Refactor');
    }

    /**
     * Register the refactor command
     */
    public register(context: vscode.ExtensionContext): void {
        const command = vscode.commands.registerCommand(
            'rspade.refactorStaticMethod',
            async () => await this.refactor_static_method()
        );
        context.subscriptions.push(command);
    }

    /**
     * Check if the refactor command should be available
     */
    public should_show_refactor_menu(): boolean {
        const editor = vscode.window.activeTextEditor;
        if (!editor) {
            return false;
        }

        const document = editor.document;

        // Only PHP files
        if (document.languageId !== 'php') {
            return false;
        }

        // Must be in ./rsx or ./app/RSpade directory
        const file_path = document.uri.fsPath;
        if (!file_path.includes('/rsx/') && !file_path.includes('\\rsx\\') &&
            !file_path.includes('/app/RSpade') && !file_path.includes('\\app\\RSpade')) {
            return false;
        }

        // Check if cursor is on a static method definition or call
        const position = editor.selection.active;
        const word_range = document.getWordRangeAtPosition(position);
        if (!word_range) {
            return false;
        }

        const word = document.getText(word_range);
        const line = document.lineAt(position.line).text;

        // Check for static method definition: public/protected/private static function method_name
        const static_definition_match = line.match(/\b(?:public|protected|private)\s+static\s+function\s+(\w+)/);
        if (static_definition_match) {
            const method_name = static_definition_match[1];
            // Cursor must be on the method name itself
            return word === method_name;
        }

        // Check for static method call: ClassName::method_name
        const static_call_match = line.match(/(\w+)::(\w+)/);
        if (static_call_match) {
            const method_name = static_call_match[2];
            // Cursor must be on the method name itself
            return word === method_name;
        }

        return false;
    }

    /**
     * Main refactor method
     */
    private async refactor_static_method(): Promise<void> {
        const editor = vscode.window.activeTextEditor;
        if (!editor) {
            vscode.window.showErrorMessage('No active editor');
            return;
        }

        const document = editor.document;
        const position = editor.selection.active;

        this.output_channel.clear();
        this.output_channel.show(true);
        this.output_channel.appendLine('=== RSpade Method Refactor ===\n');

        try {
            // Extract method information from cursor position
            const method_info = await this.extract_method_info(document, position);
            if (!method_info) {
                vscode.window.showErrorMessage('Could not identify static method at cursor position');
                return;
            }

            this.output_channel.appendLine(`Class: ${method_info.class_name}`);
            this.output_channel.appendLine(`Method: ${method_info.method_name}\n`);

            // Show input dialog for new method name
            const new_method_name = await vscode.window.showInputBox({
                title: `Global Rename Method: ${method_info.class_name}::${method_info.method_name}`,
                prompt: 'Enter new method name:',
                placeHolder: 'new_method_name',
                value: method_info.method_name,
                ignoreFocusOut: true,
                validateInput: (value: string) => {
                    if (!value) {
                        return 'Method name cannot be empty';
                    }
                    if (!/^[a-z_][a-z0-9_]*$/.test(value)) {
                        return 'Method name must be snake_case (lowercase with underscores)';
                    }
                    if (value === method_info.method_name) {
                        return 'New method name must be different from current name';
                    }
                    return null;
                }
            });

            if (!new_method_name) {
                this.output_channel.appendLine('Refactor cancelled by user');
                await vscode.commands.executeCommand('workbench.action.closePanel');
                return;
            }

            // Confirm refactoring
            const confirmation = await vscode.window.showWarningMessage(
                `Global Rename: ${method_info.class_name}::${method_info.method_name} → ${method_info.class_name}::${new_method_name}\n\n` +
                'This will rename the method across all usages in all files.',
                { modal: true },
                'Rename',
                'Cancel'
            );

            if (confirmation !== 'Rename') {
                this.output_channel.appendLine('Global rename cancelled by user');
                return;
            }

            // Save all dirty files first
            this.output_channel.appendLine('Checking for unsaved files...');
            const dirty_documents = vscode.workspace.textDocuments.filter(doc => doc.isDirty);

            if (dirty_documents.length > 0) {
                this.output_channel.appendLine(`Found ${dirty_documents.length} unsaved file(s):`);
                for (const doc of dirty_documents) {
                    this.output_channel.appendLine(`  - ${doc.fileName}`);
                }

                this.output_channel.appendLine('\nSaving all files...');
                const save_result = await vscode.workspace.saveAll(false);

                if (!save_result) {
                    const error_msg = 'Failed to save all files. Refactor operation aborted.';
                    this.output_channel.appendLine(`\nERROR: ${error_msg}`);
                    vscode.window.showErrorMessage(error_msg);
                    return;
                }

                this.output_channel.appendLine('All files saved successfully\n');
            } else {
                this.output_channel.appendLine('No unsaved files\n');
            }

            // Show output channel
            this.output_channel.show(true);

            // Show terminal and execute refactoring
            this.output_channel.appendLine(`Refactoring ${method_info.class_name}::${method_info.method_name} to ${method_info.class_name}::${new_method_name}...`);
            this.output_channel.appendLine('');

            const result = await this.execute_refactor(
                method_info.class_name,
                method_info.method_name,
                new_method_name
            );

            // Display result in terminal
            this.output_channel.appendLine('\n=== Refactor Output ===\n');
            this.output_channel.appendLine(result);
            this.output_channel.appendLine('\n=== Refactor Complete ===');

            // Check if refactor was successful
            if (result.includes('=== Refactor Complete ===') || result.trim().length > 0) {
                // Wait for filesystem changes to propagate then reload files
                // Note: Panel is kept open so developer can see results and warnings
                setTimeout(async () => {
                    await this.reload_all_open_files();
                }, 500);

                vscode.window.showInformationMessage(
                    `Successfully refactored ${method_info.class_name}::${method_info.method_name} to ${new_method_name}`
                );
            }

        } catch (error: any) {
            const error_message = error.message || String(error);
            this.output_channel.appendLine(`\nERROR: ${error_message}`);
            vscode.window.showErrorMessage(`Refactor failed: ${error_message}`);
        }
    }

    /**
     * Extract method information from cursor position
     */
    private async extract_method_info(
        document: vscode.TextDocument,
        position: vscode.Position
    ): Promise<{ class_name: string; method_name: string } | null> {
        const line = document.lineAt(position.line).text;

        // Check for static method definition: public/protected/private static function method_name
        const definition_match = line.match(/\b(?:public|protected|private)\s+static\s+function\s+(\w+)/);
        if (definition_match) {
            const method_name = definition_match[1];
            // Extract class name from the file
            const class_name = await this.extract_class_name(document);
            if (class_name) {
                return { class_name, method_name };
            }
        }

        // Check for static method call: ClassName::method_name
        const static_call_match = line.match(/(\w+)::(\w+)/);
        if (static_call_match) {
            const class_name = static_call_match[1];
            const method_name = static_call_match[2];
            return { class_name, method_name };
        }

        return null;
    }

    /**
     * Extract class name from PHP file
     */
    private async extract_class_name(document: vscode.TextDocument): Promise<string | null> {
        const text = document.getText();
        // Match actual class declaration, not @class in comments
        // Look for: class ClassName or abstract class ClassName or final class ClassName
        const class_match = text.match(/^\s*(?:abstract\s+|final\s+)?class\s+(\w+)/m);
        if (class_match) {
            return class_match[1];
        }
        return null;
    }

    /**
     * Reload all open text documents
     */
    private async reload_all_open_files(): Promise<void> {
        const text_documents = vscode.workspace.textDocuments;

        for (const document of text_documents) {
            // Skip untitled documents
            if (document.uri.scheme === 'untitled') {
                continue;
            }

            // Skip non-file schemes (git, output channels, etc)
            if (document.uri.scheme !== 'file') {
                continue;
            }

            // Get the text editor for this document
            const editors = vscode.window.visibleTextEditors.filter(
                editor => editor.document.uri.toString() === document.uri.toString()
            );

            if (editors.length > 0) {
                // Document is currently visible, reload it
                const position = editors[0].selection.active;
                const view_column = editors[0].viewColumn;

                // Close and reopen to force reload
                await vscode.commands.executeCommand('workbench.action.closeActiveEditor');
                const new_document = await vscode.workspace.openTextDocument(document.uri);
                const editor = await vscode.window.showTextDocument(new_document, view_column);

                // Restore cursor position
                editor.selection = new vscode.Selection(position, position);
                editor.revealRange(new vscode.Range(position, position));
            }
        }
    }

    /**
     * Execute the refactor command via IDE service
     */
    private async execute_refactor(
        class_name: string,
        old_method: string,
        new_method: string
    ): Promise<string> {
        // Prepare request data
        const request_data = {
            command: 'rsx:refactor:rename_php_class_function',
            arguments: [class_name, old_method, new_method]
        };

        this.output_channel.appendLine('Sending refactor request to server...');
        this.output_channel.appendLine(`Command: ${request_data.command}`);
        this.output_channel.appendLine(`Arguments: ${JSON.stringify(request_data.arguments)}\n`);

        // Grant-authenticated request (X-Ide-Token)
        const response = await this.formatting_provider.ide_service_request(
            '/refactor',
            request_data
        );

        if (!response.success) {
            throw new Error(response.error || 'Refactor command failed');
        }

        return response.output || 'Refactor completed successfully (no output)';
    }
}
