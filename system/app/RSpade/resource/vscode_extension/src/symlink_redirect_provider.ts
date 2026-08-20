import * as vscode from 'vscode';
import * as path from 'path';
import * as fs from 'fs';

/**
 * Redirects files opened from system/rsx/ symlink to their real location in rsx/
 *
 * The system/rsx/ directory is a symlink to rsx/ for framework compatibility,
 * but users should always edit files in the real rsx/ directory.
 */
export class SymlinkRedirectProvider {
    private disposables: vscode.Disposable[] = [];

    public activate(context: vscode.ExtensionContext) {
        // Watch for document opens and switches
        this.disposables.push(
            vscode.window.onDidChangeActiveTextEditor(editor => {
                if (editor) {
                    this.check_and_redirect(editor.document);
                }
            })
        );

        // Also check when window first opens or tabs change
        this.disposables.push(
            vscode.workspace.onDidOpenTextDocument(document => {
                this.check_and_redirect(document);
            })
        );

        // Check currently active editor immediately
        if (vscode.window.activeTextEditor) {
            this.check_and_redirect(vscode.window.activeTextEditor.document);
        }

        console.log('[RSpade] Symlink redirect provider activated');
    }

    private async check_and_redirect(document: vscode.TextDocument) {
        const file_path = document.uri.fsPath;

        // Check if this is a file in system/rsx/
        if (!file_path.includes('/system/rsx/') && !file_path.includes('\\system\\rsx\\')) {
            return; // Not in system/rsx/, no action needed
        }

        // Find the workspace folder
        const workspace_folder = vscode.workspace.getWorkspaceFolder(document.uri);
        if (!workspace_folder) {
            return;
        }

        const workspace_root = workspace_folder.uri.fsPath;

        // Extract the path after system/rsx/
        const system_rsx_pattern = /[\/\\]system[\/\\]rsx[\/\\](.*)/;
        const match = file_path.match(system_rsx_pattern);

        if (!match) {
            return; // Pattern doesn't match
        }

        const relative_path = match[1];
        const real_file = path.join(workspace_root, 'rsx', relative_path);

        // Check if the real file exists
        if (!fs.existsSync(real_file)) {
            // Real file doesn't exist, this might be a framework file or something else
            return;
        }

        console.log(`[RSpade] Redirecting from system/rsx/ symlink to real file:`);
        console.log(`  Symlink: ${file_path}`);
        console.log(`  Real:    ${real_file}`);

        // Check if the symlink version is pinned
        const is_pinned = document.uri.scheme === 'file' &&
                         vscode.window.tabGroups.activeTabGroup.activeTab?.isPinned;

        // If pinned, unpin it first
        if (is_pinned) {
            await vscode.commands.executeCommand('workbench.action.unpinEditor');
        }

        // Open the real file
        const real_uri = vscode.Uri.file(real_file);
        const real_document = await vscode.workspace.openTextDocument(real_uri);
        await vscode.window.showTextDocument(real_document);

        // If the original was pinned, pin the new one
        if (is_pinned) {
            await vscode.commands.executeCommand('workbench.action.pinEditor');
        }

        // Close the symlink version (now in background)
        // Find the tab with the symlink path and close it
        const tab_groups = vscode.window.tabGroups.all;
        for (const group of tab_groups) {
            for (const tab of group.tabs) {
                if (tab.input instanceof vscode.TabInputText &&
                    tab.input.uri.fsPath === file_path) {
                    await vscode.window.tabGroups.close(tab);
                    break;
                }
            }
        }

        // Show brief notification
        vscode.window.setStatusBarMessage('Redirected from system/rsx/ to rsx/', 2000);
    }

    public dispose() {
        this.disposables.forEach(d => d.dispose());
    }
}
