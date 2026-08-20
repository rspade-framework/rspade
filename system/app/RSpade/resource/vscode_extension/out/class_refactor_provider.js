"use strict";
/**
 * RSpade Class Refactor Provider
 *
 * Provides context menu refactoring options for PHP class definitions.
 * Communicates with the server-side refactor commands via the IDE service.
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
exports.RspadeClassRefactorProvider = void 0;
const vscode = __importStar(require("vscode"));
class RspadeClassRefactorProvider {
    constructor(formatting_provider, auto_rename_provider) {
        this.formatting_provider = formatting_provider;
        this.auto_rename_provider = auto_rename_provider;
        this.output_channel = vscode.window.createOutputChannel('RSpade Refactor');
    }
    /**
     * Register the refactor command
     */
    register(context) {
        const command = vscode.commands.registerCommand('rspade.refactorClass', async () => await this.refactor_class());
        context.subscriptions.push(command);
    }
    /**
     * Main refactor method
     */
    async refactor_class() {
        const editor = vscode.window.activeTextEditor;
        if (!editor) {
            vscode.window.showErrorMessage('No active editor');
            return;
        }
        const document = editor.document;
        const position = editor.selection.active;
        this.output_channel.clear();
        this.output_channel.show(true);
        this.output_channel.appendLine('=== RSpade Class Refactor ===\n');
        try {
            // Extract class name from cursor position
            const class_info = await this.extract_class_info(document, position);
            if (!class_info) {
                vscode.window.showErrorMessage('Could not identify class at cursor position');
                return;
            }
            this.output_channel.appendLine(`Class: ${class_info.class_name}\n`);
            // Show input dialog for new class name
            const new_class_name = await vscode.window.showInputBox({
                title: `Global Rename Class: ${class_info.class_name}`,
                prompt: 'Enter new class name:',
                placeHolder: 'New_Class_Name',
                value: class_info.class_name,
                ignoreFocusOut: true,
                validateInput: (value) => {
                    if (!value) {
                        return 'Class name cannot be empty';
                    }
                    if (!/^[A-Z][a-zA-Z0-9_]*$/.test(value)) {
                        return 'Class name must be PascalCase (uppercase first letter)';
                    }
                    if (value === class_info.class_name) {
                        return 'New class name must be different from current name';
                    }
                    return null;
                }
            });
            if (!new_class_name) {
                this.output_channel.appendLine('Refactor cancelled by user');
                return;
            }
            // Confirm refactoring
            const confirmation = await vscode.window.showWarningMessage(`Global Rename: ${class_info.class_name} → ${new_class_name}\n\n` +
                'This will rename the class across all usages in all files.', { modal: true }, 'Rename', 'Cancel');
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
            }
            else {
                this.output_channel.appendLine('No unsaved files\n');
            }
            // Show output channel
            this.output_channel.show(true);
            // Show terminal and execute refactoring
            this.output_channel.appendLine(`Refactoring ${class_info.class_name} to ${new_class_name}...`);
            this.output_channel.appendLine('');
            const result = await this.execute_refactor(class_info.class_name, new_class_name);
            // Display result in terminal
            this.output_channel.appendLine('\n=== Refactor Output ===\n');
            this.output_channel.appendLine(result);
            this.output_channel.appendLine('\n=== Refactor Complete ===');
            // Check if refactor was successful
            if (result.includes('=== Refactor Complete ===') || result.trim().length > 0) {
                // Wait for filesystem changes to propagate then reload files and auto-rename
                // Note: Panel is kept open so developer can see results and warnings
                setTimeout(async () => {
                    await this.reload_all_open_files();
                    // Wait another 500ms then check if current file needs renaming
                    setTimeout(async () => {
                        const editor = vscode.window.activeTextEditor;
                        if (editor) {
                            const file_path = editor.document.uri.fsPath;
                            // Only auto-rename if file is in ./rsx
                            if (file_path.includes('/rsx/') || file_path.includes('\\rsx\\')) {
                                await this.auto_rename_provider.check_and_rename(editor.document);
                            }
                        }
                    }, 500);
                }, 500);
                vscode.window.showInformationMessage(`Successfully refactored ${class_info.class_name} to ${new_class_name}`);
            }
        }
        catch (error) {
            const error_message = error.message || String(error);
            this.output_channel.appendLine(`\nERROR: ${error_message}`);
            vscode.window.showErrorMessage(`Refactor failed: ${error_message}`);
        }
    }
    /**
     * Extract class name from cursor position
     */
    async extract_class_info(document, position) {
        const line = document.lineAt(position.line).text;
        // Check for class definition at indent level 0: class ClassName or class ClassName extends Parent
        // Must be at start of line (indent level 0)
        const class_match = line.match(/^(?:abstract\s+|final\s+)?class\s+([A-Z][a-zA-Z0-9_]*)/);
        if (class_match) {
            const class_name = class_match[1];
            return { class_name };
        }
        return null;
    }
    /**
     * Reload all open text documents
     */
    async reload_all_open_files() {
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
            const editors = vscode.window.visibleTextEditors.filter(editor => editor.document.uri.toString() === document.uri.toString());
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
    async execute_refactor(old_class, new_class) {
        // Prepare request data
        const request_data = {
            command: 'rsx:refactor:rename_php_class',
            arguments: [old_class, new_class, '--skip-rename-file']
        };
        this.output_channel.appendLine('Sending refactor request to server...');
        this.output_channel.appendLine(`Command: ${request_data.command}`);
        this.output_channel.appendLine(`Arguments: ${JSON.stringify(request_data.arguments)}\n`);
        // Grant-authenticated request (X-Ide-Token)
        const response = await this.formatting_provider.ide_service_request('/refactor', request_data);
        if (!response.success) {
            throw new Error(response.error || 'Refactor command failed');
        }
        return response.output || 'Refactor completed successfully (no output)';
    }
}
exports.RspadeClassRefactorProvider = RspadeClassRefactorProvider;
//# sourceMappingURL=class_refactor_provider.js.map