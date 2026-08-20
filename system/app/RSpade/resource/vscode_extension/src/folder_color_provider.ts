import * as vscode from 'vscode';
import * as fs from 'fs';
import * as path from 'path';

/**
 * Provides folder coloring for the RSpade framework
 *
 * Colors:
 * - rsx/ - Blue (highlight important directory)
 * - system/ - Muted gray
 * - app/ - Muted gray (legacy structure)
 * - routes/ - Muted gray (legacy structure)
 * - *.expect files - Muted gray (behavioral expectation documentation)
 */
export class FolderColorProvider implements vscode.FileDecorationProvider {
    private readonly _onDidChangeFileDecorations: vscode.EventEmitter<vscode.Uri | vscode.Uri[] | undefined> =
        new vscode.EventEmitter<vscode.Uri | vscode.Uri[] | undefined>();

    public readonly onDidChangeFileDecorations: vscode.Event<vscode.Uri | vscode.Uri[] | undefined> =
        this._onDidChangeFileDecorations.event;

    /**
     * Find the RSpade project root folder (contains rsx/ and system/app/RSpade/)
     * Works in both single-folder and multi-root workspace modes
     */
    private find_rspade_root(): string | undefined {
        if (!vscode.workspace.workspaceFolders) {
            return undefined;
        }

        // Check each workspace folder for rsx/ and system/app/RSpade/ (new structure)
        // or app/RSpade/ (legacy structure)
        for (const folder of vscode.workspace.workspaceFolders) {
            const rsx_dir = path.join(folder.uri.fsPath, 'rsx');
            const system_app_rspade = path.join(folder.uri.fsPath, 'system', 'app', 'RSpade');

            // New structure: requires both rsx/ and system/app/RSpade/
            if (fs.existsSync(rsx_dir) && fs.existsSync(system_app_rspade)) {
                return folder.uri.fsPath;
            }

            // Legacy structure: just app/RSpade/
            const app_rspade = path.join(folder.uri.fsPath, 'app', 'RSpade');
            if (fs.existsSync(app_rspade)) {
                return folder.uri.fsPath;
            }
        }

        return undefined;
    }

    provideFileDecoration(
        uri: vscode.Uri,
        token: vscode.CancellationToken
    ): vscode.ProviderResult<vscode.FileDecoration> {
        if (!vscode.workspace.workspaceFolders) {
            console.log('[FolderColor] No workspace folders');
            return undefined;
        }

        const uriPath = uri.fsPath.replace(/\\/g, '/');

        // Mute .expect files (behavioral expectation documentation)
        // Only in RSpade projects to avoid affecting other workspaces
        if (uriPath.endsWith('.expect') && this.find_rspade_root()) {
            return new vscode.FileDecoration(
                undefined,
                undefined,
                new vscode.ThemeColor('descriptionForeground')
            );
        }

        // Check if this URI is a workspace folder root (for multi-root workspaces)
        const workspaceFolder = vscode.workspace.workspaceFolders.find(
            folder => folder.uri.fsPath.replace(/\\/g, '/') === uriPath
        );

        if (workspaceFolder) {
            const folderName = workspaceFolder.name.toLowerCase();
            console.log('[FolderColor] Workspace folder:', folderName);

            // Color workspace folders based on name
            if (folderName.includes('rsx')) {
                return new vscode.FileDecoration(
                    undefined,
                    undefined,
                    new vscode.ThemeColor('charts.blue')
                );
            }
            // docs, database, public - no coloring (default)
            if (folderName.includes('system')) {
                return new vscode.FileDecoration(
                    undefined,
                    undefined,
                    new vscode.ThemeColor('descriptionForeground')
                );
            }
        }

        // For single-folder workspaces, color top-level directories
        const workspaceRoot = this.find_rspade_root();
        if (!workspaceRoot) {
            return undefined;
        }
        const relativePath = uriPath.replace(workspaceRoot.replace(/\\/g, '/') + '/', '');

        // Only color top-level directories (no subdirectories)
        if (relativePath.includes('/')) {
            return undefined;
        }

        // Color blue for rsx
        if (relativePath === 'rsx') {
            return new vscode.FileDecoration(
                undefined,
                undefined,
                new vscode.ThemeColor('charts.blue')
            );
        }

        // Color muted gray for system, app, and routes
        if (relativePath === 'system' || relativePath === 'app' || relativePath === 'routes') {
            return new vscode.FileDecoration(
                undefined,
                undefined,
                new vscode.ThemeColor('descriptionForeground')
            );
        }

        return undefined;
    }
}
