"use strict";
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
exports.RspadeFileWatcher = void 0;
const vscode = __importStar(require("vscode"));
const path = __importStar(require("path"));
const child_process_1 = require("child_process");
const util_1 = require("util");
const config_1 = require("./config");
// execFile, not exec: exec runs the string through /bin/sh (dash on our platforms), which
// this project never invokes. The argv form also removes the quoting hazard entirely.
const exec_file_async = (0, util_1.promisify)(child_process_1.execFile);
class RspadeFileWatcher {
    constructor() {
        this.disposables = [];
    }
    activate(context) {
        // Watch for file rename/move events
        const watcher = vscode.workspace.createFileSystemWatcher('**/*.php');
        // Track file renames
        const file_tracker = new Map();
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
        watcher.onDidCreate(async (uri) => {
            if (this.is_php_file_in_rsx(uri)) {
                // Check if this might be a rename
                let old_path;
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
        context.subscriptions.push(vscode.workspace.onDidRenameFiles(async (e) => {
            for (const file of e.files) {
                if (this.is_php_file_in_rsx(file.newUri)) {
                    await this.format_php_file(file.newUri.fsPath);
                    // Silent update - no notification needed
                    console.log(`Updated namespace for renamed file ${path.basename(file.newUri.fsPath)}`);
                }
            }
        }));
    }
    is_php_file_in_rsx(uri) {
        const file_path = uri.fsPath;
        return file_path.endsWith('.php') &&
            (file_path.includes(path.sep + 'rsx' + path.sep) ||
                file_path.includes('/rsx/'));
    }
    async format_php_file(file_path) {
        try {
            const workspace_folder = vscode.workspace.getWorkspaceFolder(vscode.Uri.file(file_path));
            if (!workspace_folder)
                return;
            const workspace_root = workspace_folder.uri.fsPath;
            const orchestrator_path = path.join(workspace_root, '.vscode', 'formatters', 'orchestrator.py');
            const python_cmd = (0, config_1.get_python_command)();
            await exec_file_async(python_cmd, [orchestrator_path, file_path], { cwd: workspace_root });
        }
        catch (error) {
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
exports.RspadeFileWatcher = RspadeFileWatcher;
//# sourceMappingURL=file_watcher.js.map