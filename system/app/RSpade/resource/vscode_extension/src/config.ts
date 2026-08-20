import * as vscode from 'vscode';

export function get_config() {
    return vscode.workspace.getConfiguration('rspade');
}

export function get_python_command(): string {
    const custom_path = get_config().get<string>('pythonPath');
    if (custom_path && custom_path.trim() !== '') {
        return custom_path;
    }
    
    // Default based on platform
    return process.platform === 'win32' ? 'python' : 'python3';
}