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
exports.GitStatusProvider = void 0;
const vscode = __importStar(require("vscode"));
const path = __importStar(require("path"));
const http = __importStar(require("http"));
const https = __importStar(require("https"));
const url_1 = require("url");
const ide_bridge_config_1 = require("./ide_bridge_config");
/**
 * Git status provider that fetches status from the IDE service and colors files
 * based on git status without using local git.
 *
 * AUTH: LOCAL-FILE GRANT. Server URL from project-root .env APP_URL; every request
 * carries the X-Ide-Token header (trimmed ide-grant-*.token contents).
 */
class GitStatusProvider {
    constructor(rspade_root) {
        this._onDidChangeFileDecorations = new vscode.EventEmitter();
        this.onDidChangeFileDecorations = this._onDidChangeFileDecorations.event;
        this.git_status = new Map();
        this.rspade_root = rspade_root;
        // Initial fetch
        this.refresh_git_status();
        // Fetch on file save
        vscode.workspace.onDidSaveTextDocument(() => {
            this.refresh_git_status();
        });
        // Fetch on window focus
        vscode.window.onDidChangeWindowState(e => {
            if (e.focused) {
                this.refresh_git_status();
            }
        });
    }
    async refresh_git_status() {
        if (!this.rspade_root) {
            return;
        }
        try {
            const response = await this.make_request('/git', {});
            if (response.success) {
                const new_status = new Map();
                for (const file of response.modified || []) {
                    new_status.set(file, 'modified');
                }
                for (const file of response.added || []) {
                    new_status.set(file, 'added');
                }
                for (const file of response.conflicts || []) {
                    new_status.set(file, 'conflict');
                }
                const changed_uris = [];
                // First pass: update or remove existing tracked files
                for (const [file_path, old_status] of this.git_status.entries()) {
                    const new_file_status = new_status.get(file_path);
                    if (new_file_status !== old_status) {
                        const file_uri = vscode.Uri.file(path.join(this.rspade_root, file_path));
                        changed_uris.push(file_uri);
                        if (new_file_status === undefined) {
                            this.git_status.delete(file_path);
                        }
                        else {
                            this.git_status.set(file_path, new_file_status);
                        }
                    }
                }
                // Second pass: add newly tracked files
                for (const [file_path, status] of new_status.entries()) {
                    if (!this.git_status.has(file_path)) {
                        this.git_status.set(file_path, status);
                        const file_uri = vscode.Uri.file(path.join(this.rspade_root, file_path));
                        changed_uris.push(file_uri);
                    }
                }
                if (changed_uris.length > 0) {
                    this._onDidChangeFileDecorations.fire(changed_uris);
                }
            }
        }
        catch (error) {
            console.error('[GitStatus] Failed to fetch status:', error);
        }
    }
    /**
     * POST an IDE service request with the X-Ide-Token grant header.
     */
    make_request(endpoint, data) {
        return new Promise((resolve, reject) => {
            if (!this.rspade_root) {
                reject(new Error('RSpade project root not found'));
                return;
            }
            let server_url;
            let token;
            try {
                server_url = (0, ide_bridge_config_1.resolveServerUrl)(this.rspade_root);
                token = (0, ide_bridge_config_1.readIdeToken)(this.rspade_root);
            }
            catch (e) {
                reject(e);
                return;
            }
            const parsed_url = new url_1.URL(server_url);
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
                    }
                    catch (e) {
                        console.error(`[GitStatus] JSON parse error (HTTP ${res.statusCode}). Full response: ${response_data.substring(0, 200)}`);
                        reject(new Error(res.statusCode !== 200 ? `HTTP ${res.statusCode}` : 'Invalid JSON response'));
                    }
                });
            });
            req.on('error', (err) => {
                console.error(`[GitStatus] Request error:`, err);
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
    provideFileDecoration(uri) {
        if (!this.rspade_root) {
            return undefined;
        }
        const relative_path = path.relative(this.rspade_root, uri.fsPath).replace(/\\/g, '/');
        const status = this.git_status.get(relative_path);
        if (!status) {
            return undefined;
        }
        if (status === 'conflict') {
            const decoration = new vscode.FileDecoration();
            decoration.color = new vscode.ThemeColor('charts.red');
            return decoration;
        }
        else if (status === 'modified' || status === 'added') {
            const decoration = new vscode.FileDecoration();
            decoration.color = new vscode.ThemeColor('gitDecoration.modifiedResourceForeground');
            return decoration;
        }
        return undefined;
    }
}
exports.GitStatusProvider = GitStatusProvider;
//# sourceMappingURL=git_status_provider.js.map