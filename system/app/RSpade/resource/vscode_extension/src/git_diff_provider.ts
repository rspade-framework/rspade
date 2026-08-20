import * as vscode from 'vscode';
import * as path from 'path';
import * as http from 'http';
import * as https from 'https';
import { URL } from 'url';
import { resolveServerUrl, readIdeToken } from './ide_bridge_config';

interface DiffResponse {
    success: boolean;
    added: number[][];
    modified: number[][];
    deleted: number[][];
}

/**
 * Git diff provider - shows line-level change indicators in gutter.
 *
 * AUTH: LOCAL-FILE GRANT. Server URL from project-root .env APP_URL; every request
 * carries the X-Ide-Token header (trimmed ide-grant-*.token contents).
 */
export class GitDiffProvider {
    private rspade_root: string | undefined;

    private added_decoration: vscode.TextEditorDecorationType;
    private modified_decoration: vscode.TextEditorDecorationType;
    private deleted_decoration: vscode.TextEditorDecorationType;

    // Track git diff state and local modifications per document
    private git_state: Map<string, DiffResponse> = new Map();
    private original_content: Map<string, string> = new Map();

    constructor(rspade_root: string | undefined) {
        this.rspade_root = rspade_root;

        this.added_decoration = vscode.window.createTextEditorDecorationType({
            isWholeLine: true,
            overviewRulerColor: 'rgba(0, 255, 0, 0.6)',
            overviewRulerLane: vscode.OverviewRulerLane.Left,
            gutterIconPath: this.create_colored_bar('#28a745'),
            gutterIconSize: 'contain'
        });

        this.modified_decoration = vscode.window.createTextEditorDecorationType({
            isWholeLine: true,
            overviewRulerColor: 'rgba(33, 150, 243, 0.6)',
            overviewRulerLane: vscode.OverviewRulerLane.Left,
            gutterIconPath: this.create_colored_bar('#2196f3'),
            gutterIconSize: 'contain'
        });

        this.deleted_decoration = vscode.window.createTextEditorDecorationType({
            isWholeLine: true,
            overviewRulerColor: 'rgba(244, 67, 54, 0.6)',
            overviewRulerLane: vscode.OverviewRulerLane.Left,
            gutterIconPath: this.create_colored_bar('#f44336'),
            gutterIconSize: 'contain'
        });
    }

    activate(context: vscode.ExtensionContext) {
        // Track document changes for real-time line marking
        context.subscriptions.push(
            vscode.workspace.onDidChangeTextDocument(e => {
                const editor = vscode.window.activeTextEditor;
                if (editor && editor.document === e.document) {
                    this.update_decorations_for_changes(editor);
                }
            })
        );

        // Refresh git diff on file save
        context.subscriptions.push(
            vscode.workspace.onDidSaveTextDocument(async document => {
                const editor = vscode.window.activeTextEditor;
                if (editor && editor.document === document) {
                    await this.fetch_git_diff(editor);
                    this.original_content.set(document.uri.toString(), document.getText());
                    this.update_decorations_for_changes(editor);
                }
            })
        );

        // Refresh on active editor change (only if clean)
        context.subscriptions.push(
            vscode.window.onDidChangeActiveTextEditor(async editor => {
                if (editor) {
                    const doc_key = editor.document.uri.toString();

                    if (!editor.document.isDirty) {
                        await this.fetch_git_diff(editor);
                        this.original_content.set(doc_key, editor.document.getText());
                    }

                    this.update_decorations_for_changes(editor);
                }
            })
        );

        // Initial refresh for current editor
        const editor = vscode.window.activeTextEditor;
        if (editor) {
            this.fetch_git_diff(editor).then(() => {
                this.original_content.set(editor.document.uri.toString(), editor.document.getText());
                this.update_decorations_for_changes(editor);
            });
        }
    }

    private create_colored_bar(color: string): vscode.Uri {
        const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="3" height="16"><rect width="3" height="16" fill="${color}"/></svg>`;
        const data_uri = 'data:image/svg+xml;base64,' + Buffer.from(svg).toString('base64');
        return vscode.Uri.parse(data_uri);
    }

    private async fetch_git_diff(editor: vscode.TextEditor) {
        if (!this.rspade_root) {
            return;
        }

        try {
            const relative_path = path.relative(this.rspade_root, editor.document.uri.fsPath).replace(/\\/g, '/');
            const response = await this.make_request('/git/diff', { file: relative_path });

            if (response.success) {
                this.git_state.set(editor.document.uri.toString(), response);
            }
        } catch (error) {
            console.error('[GitDiff] Failed to fetch git diff:', error);
        }
    }

    private update_decorations_for_changes(editor: vscode.TextEditor) {
        const doc_key = editor.document.uri.toString();
        const git_diff = this.git_state.get(doc_key);
        const original_text = this.original_content.get(doc_key);

        if (!git_diff) {
            return;
        }

        const added_lines = new Set<number>();
        const modified_lines = new Set<number>();
        const deleted_lines = new Set<number>();

        for (const [start, end] of git_diff.added) {
            for (let line = start; line <= end; line++) {
                added_lines.add(line);
            }
        }

        for (const [start, end] of git_diff.modified) {
            for (let line = start; line <= end; line++) {
                modified_lines.add(line);
            }
        }

        for (const [start, end] of git_diff.deleted) {
            for (let line = start; line <= end; line++) {
                deleted_lines.add(line);
            }
        }

        if (editor.document.isDirty && original_text) {
            const local_changes = this.compute_diff(original_text, editor.document.getText());

            for (const line of local_changes.added) {
                added_lines.add(line);
            }

            for (const line of local_changes.modified) {
                modified_lines.add(line);
            }

            for (const line of local_changes.deleted) {
                deleted_lines.add(line);
            }
        }

        const added_ranges = Array.from(added_lines).map(line => new vscode.Range(line - 1, 0, line - 1, 0));
        const modified_ranges = Array.from(modified_lines).map(line => new vscode.Range(line - 1, 0, line - 1, 0));
        const deleted_ranges = Array.from(deleted_lines).map(line => new vscode.Range(line - 1, 0, line - 1, 0));

        editor.setDecorations(this.added_decoration, added_ranges);
        editor.setDecorations(this.modified_decoration, modified_ranges);
        editor.setDecorations(this.deleted_decoration, deleted_ranges);
    }

    private compute_diff(original: string, current: string): { added: number[], modified: number[], deleted: number[] } {
        const original_lines = original.split('\n');
        const current_lines = current.split('\n');

        const m = original_lines.length;
        const n = current_lines.length;
        const lcs: number[][] = Array(m + 1).fill(0).map(() => Array(n + 1).fill(0));

        for (let i = 1; i <= m; i++) {
            for (let j = 1; j <= n; j++) {
                if (original_lines[i - 1] === current_lines[j - 1]) {
                    lcs[i][j] = lcs[i - 1][j - 1] + 1;
                } else {
                    lcs[i][j] = Math.max(lcs[i - 1][j], lcs[i][j - 1]);
                }
            }
        }

        const added: number[] = [];
        const modified: number[] = [];
        const deleted: number[] = [];

        let i = m;
        let j = n;

        while (i > 0 || j > 0) {
            if (i > 0 && j > 0 && original_lines[i - 1] === current_lines[j - 1]) {
                i--;
                j--;
            } else if (j > 0 && (i === 0 || lcs[i][j - 1] >= lcs[i - 1][j])) {
                added.push(j);
                j--;
            } else if (i > 0) {
                deleted.push(j + 1);
                i--;
            }
        }

        return { added, modified, deleted };
    }

    /**
     * POST an IDE service request with the X-Ide-Token grant header.
     */
    private make_request(endpoint: string, data: any): Promise<any> {
        return new Promise((resolve, reject) => {
            if (!this.rspade_root) {
                reject(new Error('RSpade project root not found'));
                return;
            }

            let server_url: string;
            let token: string;
            try {
                server_url = resolveServerUrl(this.rspade_root);
                token = readIdeToken(this.rspade_root);
            } catch (e: any) {
                reject(e);
                return;
            }

            const parsed_url = new URL(server_url);
            const is_https = parsed_url.protocol === 'https:';
            const port = parsed_url.port || (is_https ? 443 : 80);

            const full_path = `/_ide/service${endpoint}`;
            const body_str = JSON.stringify(data);

            const options = {
                hostname: parsed_url.hostname,
                port: port,
                path: full_path,
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Content-Length': Buffer.byteLength(body_str),
                    'X-Ide-Token': token
                },
                timeout: 30000,
                rejectUnauthorized: false
            };

            const client = is_https ? https : http;
            const req = client.request(options, (res) => {
                let response_data = '';
                res.on('data', chunk => response_data += chunk);
                res.on('end', () => {
                    try {
                        resolve(JSON.parse(response_data));
                    } catch (e) {
                        console.error(`[GitDiff] JSON parse error (HTTP ${res.statusCode}). Full response: ${response_data.substring(0, 200)}`);
                        reject(new Error(res.statusCode !== 200 ? `HTTP ${res.statusCode}` : 'Invalid JSON response'));
                    }
                });
            });

            req.on('error', (err) => {
                console.error(`[GitDiff] Request error:`, err);
                reject(err);
            });
            req.on('timeout', () => {
                req.destroy();
                reject(new Error('Request timed out'));
            });
            req.write(body_str);
            req.end();
        });
    }

    dispose() {
        this.added_decoration.dispose();
        this.modified_decoration.dispose();
        this.deleted_decoration.dispose();
    }
}
