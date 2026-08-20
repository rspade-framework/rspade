import * as vscode from 'vscode';
import * as path from 'path';
import { execFile } from 'child_process';
import { promisify } from 'util';
import { get_python_command } from './config';

// execFile, not exec: exec runs the string through /bin/sh (dash on our platforms), which
// this project never invokes. The argv form also removes the quoting hazard entirely.
const exec_file_async = promisify(execFile);

export class RspadeFileWatcher {
    private disposables: vscode.Disposable[] = [];
    
    activate(context: vscode.ExtensionContext) {
        // Watch for file rename/move events
        const watcher = vscode.workspace.createFileSystemWatcher('**/*.php');
        
        // Track file renames
        const file_tracker = new Map<string, string>();
        
        // Before delete, store the content
        watcher.onDidDelete(uri => {
            if (this.is_php_file_in_rsx(uri)) {
                // Store file path for potential rename detection
                file_tracker.set(uri.fsPath, uri.fsPath);
                
                // Clean up old entries after 1 second
                setTimeout(() => {
                    file_tracker.delete(uri.fsPath);
                }, 1000);
            }
        });
        
        // On create, check if it's a rename
        watcher.onDidCreate(async uri => {
            if (this.is_php_file_in_rsx(uri)) {
                // Check if this might be a rename
                let old_path: string | undefined;
                
                // Look for recently deleted files
                for (const [deleted_path, _] of file_tracker) {
                    // If the file was deleted within the last second, it might be a rename
                    if (path.basename(deleted_path) !== path.basename(uri.fsPath)) {
                        old_path = deleted_path;
                        break;
                    }
                }
                
                // Format the file to update namespace
                await this.format_php_file(uri.fsPath);
                
                if (old_path) {
                    // Silent update - no notification needed
                    console.log(`Updated namespace for moved file ${path.basename(uri.fsPath)}`);
                    file_tracker.delete(old_path);
                }
            }
        });
        
        this.disposables.push(watcher);
        context.subscriptions.push(...this.disposables);
        
        // Also handle explicit rename commands
        context.subscriptions.push(
            vscode.workspace.onDidRenameFiles(async e => {
                for (const file of e.files) {
                    if (this.is_php_file_in_rsx(file.newUri)) {
                        await this.format_php_file(file.newUri.fsPath);
                        // Silent update - no notification needed
                        console.log(`Updated namespace for renamed file ${path.basename(file.newUri.fsPath)}`);
                    }
                }
            })
        );
    }
    
    private is_php_file_in_rsx(uri: vscode.Uri): boolean {
        const file_path = uri.fsPath;
        return file_path.endsWith('.php') && 
               (file_path.includes(path.sep + 'rsx' + path.sep) || 
                file_path.includes('/rsx/'));
    }
    
    private async format_php_file(file_path: string): Promise<void> {
        try {
            const workspace_folder = vscode.workspace.getWorkspaceFolder(vscode.Uri.file(file_path));
            if (!workspace_folder) return;
            
            const workspace_root = workspace_folder.uri.fsPath;
            const orchestrator_path = path.join(workspace_root, '.vscode', 'formatters', 'orchestrator.py');
            
            const python_cmd = get_python_command();

            await exec_file_async(python_cmd, [orchestrator_path, file_path], { cwd: workspace_root });
        } catch (error) {
            console.error('Failed to format PHP file:', error);
        }
    }
    
    dispose() {
        for (const disposable of this.disposables) {
            disposable.dispose();
        }
        this.disposables = [];
    }
}