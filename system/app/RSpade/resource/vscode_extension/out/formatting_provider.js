"use strict";
/**
 * RSpade Formatting Provider
 *
 * Handles code formatting via remote IDE service endpoints. All formatting is
 * performed on the server - no local PHP execution.
 *
 * AUTH: LOCAL-FILE GRANT. Server URL comes from the project-root .env APP_URL and
 * every request carries the `X-Ide-Token` header (trimmed ide-grant-*.token
 * contents, read fresh from local disk). No session handshake, no request signing,
 * no response signature validation.
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
exports.RspadeFormattingProvider = void 0;
const vscode = __importStar(require("vscode"));
const path = __importStar(require("path"));
const fs = __importStar(require("fs"));
const https = __importStar(require("https"));
const http = __importStar(require("http"));
const util_1 = require("util");
const ide_bridge_config_1 = require("./ide_bridge_config");
const read_file = (0, util_1.promisify)(fs.readFile);
const write_file = (0, util_1.promisify)(fs.writeFile);
class RspadeFormattingProvider {
    constructor() {
        this.output_channel = vscode.window.createOutputChannel('RSpade Formatter');
        console.log('[RSpade Formatter] Provider initialized');
        this.output_channel.appendLine('=== RSpade Formatter Initialized ===');
        this.output_channel.appendLine(`Time: ${new Date().toISOString()}`);
        this.output_channel.appendLine('Ready to format PHP files via remote server');
    }
    async provideDocumentFormattingEdits(document, _options, _token) {
        console.log(`[RSpade Formatter] Format request: ${path.basename(document.fileName)}`);
        this.output_channel.appendLine(`\n=== FORMAT REQUEST ===`);
        this.output_channel.appendLine(`File: ${document.fileName}`);
        this.output_channel.appendLine(`Language: ${document.languageId}`);
        this.output_channel.appendLine(`Time: ${new Date().toISOString()}`);
        if (document.languageId !== 'php') {
            this.output_channel.appendLine('Result: Skipped (not a PHP file)');
            return [];
        }
        try {
            const original_text = document.getText();
            this.output_channel.appendLine(`Original text length: ${original_text.length} chars`);
            const rspade_root = (0, ide_bridge_config_1.find_rspade_root)();
            if (!rspade_root) {
                throw new Error('RSpade project root not found');
            }
            let relative_path = path.relative(rspade_root, document.uri.fsPath).replace(/\\/g, '/');
            this.output_channel.appendLine(`Relative path: ${relative_path}`);
            this.output_channel.appendLine(`Project root: ${rspade_root}`);
            const formatted_text = await this.format_via_server(rspade_root, relative_path, original_text);
            if (formatted_text !== original_text) {
                const full_range = new vscode.Range(document.positionAt(0), document.positionAt(original_text.length));
                this.output_channel.appendLine(`Result: SUCCESS - Content changed`);
                this.output_channel.appendLine(`Formatted text length: ${formatted_text.length} chars`);
                return [vscode.TextEdit.replace(full_range, formatted_text)];
            }
            this.output_channel.appendLine('Result: SUCCESS - Content unchanged');
            return [];
        }
        catch (error) {
            console.error('[RSpade Formatter] Format failed:', error.message);
            this.output_channel.appendLine(`Result: ERROR`);
            this.output_channel.appendLine(`Error message: ${error.message}`);
            vscode.window.showErrorMessage(`RSpade formatting failed: ${error.message || error}`);
            return [];
        }
    }
    async update_namespace_only(document) {
        this.output_channel.appendLine(`\n=== NAMESPACE UPDATE ===`);
        this.output_channel.appendLine(`File: ${document.fileName}`);
        this.output_channel.appendLine(`Language: ${document.languageId}`);
        if (document.languageId !== 'php') {
            this.output_channel.appendLine('Skipped: Not a PHP file');
            return;
        }
        try {
            if (document.isDirty) {
                this.output_channel.appendLine('Saving unsaved changes...');
                await document.save();
                this.output_channel.appendLine('Document saved');
            }
            const rspade_root = (0, ide_bridge_config_1.find_rspade_root)();
            if (!rspade_root) {
                this.output_channel.appendLine('[ERROR] RSpade project root not found');
                throw new Error('RSpade project root not found');
            }
            let relative_path = path.relative(rspade_root, document.uri.fsPath).replace(/\\/g, '/');
            this.output_channel.appendLine(`Relative path: ${relative_path}`);
            const content = await read_file(document.uri.fsPath, 'utf8');
            this.output_channel.appendLine(`File content length: ${content.length} chars`);
            await this.format_via_server(rspade_root, relative_path, content);
            this.output_channel.appendLine('Namespace update completed successfully');
        }
        catch (error) {
            console.error('[RSpade Formatter] Namespace update failed:', error.message);
            this.output_channel.appendLine(`[ERROR] ${error.message}`);
            vscode.window.showErrorMessage(`RSpade namespace update failed: ${error.message || error}`);
        }
    }
    async format_via_server(rspade_root, relative_path, content) {
        this.output_channel.appendLine('\n--- SERVER FORMATTING ---');
        this.output_channel.appendLine(`File path: ${relative_path}`);
        this.output_channel.appendLine(`Content length: ${content.length} chars`);
        const temp_file_path = relative_path + '.formatting.tmp';
        const full_temp_path = path.join(rspade_root, temp_file_path);
        this.output_channel.appendLine(`Creating temp file: ${temp_file_path}`);
        await write_file(full_temp_path, content, 'utf8');
        this.output_channel.appendLine('Temp file written successfully');
        try {
            const request_data = {
                file: temp_file_path,
                return_content: true
            };
            this.output_channel.appendLine(`Request data: ${JSON.stringify(request_data)}`);
            const response = await this.make_request(rspade_root, '/format', request_data);
            if (!response.success) {
                this.output_channel.appendLine(`[ERROR] Server formatting failed - ${response.error}`);
                throw new Error(response.error || 'Formatting failed');
            }
            try {
                fs.unlinkSync(full_temp_path);
                this.output_channel.appendLine('Temp file cleaned up');
            }
            catch (e) {
                this.output_channel.appendLine(`[WARNING] Failed to clean up temp file - ${e.message}`);
            }
            const formatted_content = response.content || content;
            this.output_channel.appendLine(`Formatted content length: ${formatted_content.length} chars`);
            this.output_channel.appendLine(`Content changed: ${formatted_content !== content}`);
            return formatted_content;
        }
        catch (error) {
            this.output_channel.appendLine(`[ERROR] during formatting: ${error.message}`);
            try {
                fs.unlinkSync(full_temp_path);
                this.output_channel.appendLine('Temp file cleaned up after error');
            }
            catch (e) {
                this.output_channel.appendLine(`[WARNING] Failed to clean up temp file - ${e.message}`);
            }
            throw error;
        }
    }
    /**
     * Public grant-authenticated IDE service request, shared by sibling providers
     * (refactor / class-refactor / sort-methods). Resolves the project root and
     * POSTs to /_ide/service<endpoint> with the X-Ide-Token grant header.
     */
    async ide_service_request(endpoint, data) {
        const rspade_root = (0, ide_bridge_config_1.find_rspade_root)();
        if (!rspade_root) {
            throw new Error('RSpade project root not found');
        }
        return this.make_request(rspade_root, endpoint, data);
    }
    make_request(rspade_root, endpoint, data) {
        return new Promise((resolve, reject) => {
            this.output_channel.appendLine('\n--- HTTP REQUEST ---');
            let server_url;
            let token;
            try {
                server_url = (0, ide_bridge_config_1.resolveServerUrl)(rspade_root);
                token = (0, ide_bridge_config_1.readIdeToken)(rspade_root);
            }
            catch (e) {
                this.output_channel.appendLine(`[ERROR] ${e.message}`);
                reject(e);
                return;
            }
            const url = new URL(server_url);
            const is_https = url.protocol === 'https:';
            const http_module = is_https ? https : http;
            const body = JSON.stringify(data);
            const options = {
                hostname: url.hostname,
                port: url.port || (is_https ? 443 : 80),
                path: '/_ide/service' + endpoint,
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Content-Length': Buffer.byteLength(body),
                    'X-Ide-Token': token
                },
                timeout: 30000,
                rejectUnauthorized: false
            };
            this.output_channel.appendLine(`Full URL: ${is_https ? 'https' : 'http'}://${options.hostname}:${options.port}${options.path}`);
            this.output_channel.appendLine(`Request body size: ${Buffer.byteLength(body)} bytes`);
            const start_time = Date.now();
            const req = http_module.request(options, (res) => {
                let response_data = '';
                this.output_channel.appendLine(`\n--- HTTP RESPONSE ---`);
                this.output_channel.appendLine(`Status: ${res.statusCode}`);
                res.on('data', (chunk) => {
                    response_data += chunk;
                });
                res.on('end', () => {
                    const elapsed = Date.now() - start_time;
                    console.log(`[RSpade Formatter] Response: ${res.statusCode} in ${elapsed}ms`);
                    this.output_channel.appendLine(`Response time: ${elapsed}ms`);
                    this.output_channel.appendLine(`Response body size: ${response_data.length} bytes`);
                    try {
                        const response = JSON.parse(response_data);
                        if (res.statusCode !== 200) {
                            this.output_channel.appendLine(`[ERROR] ${response.error || `HTTP ${res.statusCode}`}`);
                            reject(new Error(response.error || `HTTP ${res.statusCode}`));
                        }
                        else {
                            this.output_channel.appendLine('Result: SUCCESS');
                            resolve(response);
                        }
                    }
                    catch (e) {
                        if (res.statusCode !== 200) {
                            this.output_channel.appendLine(`[ERROR] HTTP ${res.statusCode}: ${response_data.substring(0, 200)}`);
                            reject(new Error(`HTTP ${res.statusCode}`));
                        }
                        else {
                            this.output_channel.appendLine(`[ERROR] Failed to parse response - ${e.message}`);
                            reject(new Error('Invalid response from server'));
                        }
                    }
                });
            });
            req.on('error', (error) => {
                const elapsed = Date.now() - start_time;
                console.error(`[RSpade Formatter] Request failed after ${elapsed}ms:`, error.message);
                this.output_channel.appendLine(`\n[ERROR] after ${elapsed}ms: ${error.message}`);
                reject(error);
            });
            req.on('timeout', () => {
                this.output_channel.appendLine('\n[ERROR] Request timed out after 30 seconds');
                req.destroy();
                reject(new Error('Request timed out'));
            });
            req.write(body);
            req.end();
        });
    }
}
exports.RspadeFormattingProvider = RspadeFormattingProvider;
//# sourceMappingURL=formatting_provider.js.map