"use strict";
/**
 * RSpade IDE Bridge Client
 *
 * Centralized client for communicating with RSpade framework IDE helper endpoints.
 *
 * SERVER URL: resolved from the project-root .env APP_URL (see ide_bridge_config).
 * APP_URL already carries the scheme, so there is no protocol probing.
 *
 * AUTHENTICATION: LOCAL-FILE GRANT. Every request carries the header
 * `X-Ide-Token: <token>`, where <token> is the trimmed contents of the on-disk
 * ide-grant-*.token file (read fresh from local disk on each request). Possession
 * of the file - which lives outside the web docroot and is mode-restricted - is
 * the grant. There is no session handshake, no request signing, and no response
 * signature validation.
 *
 * ERROR HANDLING:
 * - Fails loud with descriptive errors; NO silent fallbacks.
 * - Retries ONCE on a transient connection failure.
 * - HTTP 401 means the grant token is missing/invalid - surfaced, never looped.
 * - Logs all activity to the output channel.
 *
 * USAGE:
 *
 * ```typescript
 * import { IdeBridgeClient } from './ide_bridge_client';
 * const client = new IdeBridgeClient(output_channel);
 * const response = await client.request('/_ide/service/your_endpoint', { p: 'v' });
 * ```
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
exports.IdeBridgeClient = void 0;
const vscode = __importStar(require("vscode"));
const https = __importStar(require("https"));
const http = __importStar(require("http"));
const ide_bridge_config_1 = require("./ide_bridge_config");
class IdeBridgeClient {
    constructor(output_channel) {
        this.output_channel = output_channel || vscode.window.createOutputChannel('RSpade IDE Bridge');
        this.output_channel.appendLine('=== RSpade IDE Bridge Client Initialized ===');
        this.output_channel.appendLine(`Time: ${new Date().toISOString()}`);
    }
    /**
     * Make a grant-authenticated request to an IDE helper endpoint.
     * Handles URL/token resolution and a single retry on connection failure.
     */
    async request(endpoint, data = {}, method = 'GET') {
        this.output_channel.appendLine(`\n=== IDE BRIDGE REQUEST ===`);
        this.output_channel.appendLine(`Endpoint: ${endpoint}`);
        this.output_channel.appendLine(`Method: ${method}`);
        this.output_channel.appendLine(`Time: ${new Date().toISOString()}`);
        return this.make_request_with_retry(endpoint, data, method, 0);
    }
    /**
     * Query URL to resolve it to a route (controller/method)
     */
    async queryUrl(url) {
        return this.request('/_ide/service/resolve_url', { url }, 'GET');
    }
    /**
     * Check if a JavaScript class extends another class (anywhere in the chain)
     */
    async js_is_subclass_of(subclass, superclass) {
        return this.request('/_ide/service/js_is_subclass_of', { subclass, superclass }, 'GET');
    }
    /**
     * Check if a PHP class extends another class (anywhere in the chain)
     */
    async php_is_subclass_of(subclass, superclass) {
        return this.request('/_ide/service/php_is_subclass_of', { subclass, superclass }, 'GET');
    }
    /**
     * Trigger incremental manifest build (best-effort; errors are non-fatal here).
     */
    async manifest_build() {
        try {
            return await this.request('/_ide/service/manifest_build', {}, 'GET');
        }
        catch (error) {
            console.warn('[IdeBridge] Manifest build failed:', error.message);
            return { success: false };
        }
    }
    async make_request_with_retry(endpoint, data, method, retry_count) {
        if (retry_count > 0) {
            this.output_channel.appendLine(`\n--- RETRY ATTEMPT ${retry_count} ---`);
        }
        try {
            return await this.make_http_request(endpoint, data, method);
        }
        catch (error) {
            const error_msg = error.message || '';
            // Transient connection failure - retry once with a freshly resolved URL/token.
            if (retry_count === 0 &&
                (error_msg.includes('ECONNREFUSED') || error_msg.includes('ENOTFOUND') ||
                    error_msg.includes('ETIMEDOUT') || error_msg.includes('ECONNRESET') ||
                    error_msg.includes('getaddrinfo'))) {
                this.output_channel.appendLine('[WARNING] Connection failed, retrying once...');
                return this.make_request_with_retry(endpoint, data, method, retry_count + 1);
            }
            // 401 = grant token missing/invalid. Surface actionably; do NOT loop.
            if (error_msg.includes('HTTP 401')) {
                this.output_channel.appendLine('[ERROR] IDE bridge rejected the grant token (HTTP 401). The ide-grant-*.token is missing or invalid - reload the site in a browser (dev mode) to re-mint it.');
            }
            // Not recoverable or already retried - fail loud.
            this.show_error_status('IDE Bridge request failed');
            throw error;
        }
    }
    make_http_request(endpoint, data, method) {
        return new Promise((resolve, reject) => {
            // Resolve server URL and grant token fresh from local disk on every request.
            let server_url;
            let token;
            try {
                const rspade_root = (0, ide_bridge_config_1.find_rspade_root)();
                if (!rspade_root) {
                    throw new Error('RSpade project root not found (no workspace folder contains system/app/RSpade).');
                }
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
            // GET encodes data as query string; POST sends a JSON body.
            let body = '';
            let full_path = endpoint;
            if (method === 'GET' && Object.keys(data).length > 0) {
                const params = new URLSearchParams(data);
                full_path += (endpoint.includes('?') ? '&' : '?') + params.toString();
            }
            else if (method === 'POST') {
                body = JSON.stringify(data);
            }
            const headers = {
                'Content-Type': 'application/json',
                'X-Ide-Token': token
            };
            if (body) {
                headers['Content-Length'] = Buffer.byteLength(body);
            }
            const options = {
                hostname: url.hostname,
                port: url.port || (is_https ? 443 : 80),
                path: full_path,
                method: method,
                headers: headers,
                timeout: 30000,
                rejectUnauthorized: false
            };
            this.output_channel.appendLine(`Full URL: ${is_https ? 'https' : 'http'}://${options.hostname}:${options.port}${options.path}`);
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
                    this.output_channel.appendLine(`Response time: ${elapsed}ms`);
                    this.output_channel.appendLine(`Response size: ${response_data.length} bytes`);
                    try {
                        const response = JSON.parse(response_data);
                        if (res.statusCode !== 200) {
                            const error = new Error(response.error || `HTTP ${res.statusCode}`);
                            this.output_channel.appendLine(`[ERROR] ${error.message}`);
                            reject(error);
                        }
                        else {
                            this.output_channel.appendLine('Result: SUCCESS');
                            this.clear_status_bar();
                            resolve(response);
                        }
                    }
                    catch (e) {
                        // Non-JSON error body (e.g. a bare 401 string) - surface with status.
                        if (res.statusCode !== 200) {
                            const error = new Error(`HTTP ${res.statusCode}`);
                            this.output_channel.appendLine(`[ERROR] ${error.message}: ${response_data.substring(0, 200)}`);
                            reject(error);
                        }
                        else {
                            const error = new Error(`Failed to parse response: ${e.message}`);
                            this.output_channel.appendLine(`[ERROR] ${error.message}`);
                            reject(error);
                        }
                    }
                });
            });
            req.on('error', (error) => {
                const elapsed = Date.now() - start_time;
                this.output_channel.appendLine(`\n[ERROR] after ${elapsed}ms: ${error.message}`);
                reject(error);
            });
            req.on('timeout', () => {
                this.output_channel.appendLine('\n[ERROR] Request timed out after 30 seconds');
                req.destroy();
                reject(new Error('Request timed out'));
            });
            if (body) {
                req.write(body);
            }
            req.end();
        });
    }
    show_error_status(message) {
        if (!this.status_bar_item) {
            this.status_bar_item = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Left, 0);
            this.status_bar_item.command = 'workbench.action.output.toggleOutput';
            this.status_bar_item.tooltip = 'Click to view RSpade output';
        }
        this.status_bar_item.text = `$(error) RSpade: ${message}`;
        this.status_bar_item.backgroundColor = new vscode.ThemeColor('statusBarItem.errorBackground');
        this.status_bar_item.show();
        setTimeout(() => {
            this.clear_status_bar();
        }, 5000);
    }
    clear_status_bar() {
        if (this.status_bar_item) {
            this.status_bar_item.hide();
        }
    }
}
exports.IdeBridgeClient = IdeBridgeClient;
//# sourceMappingURL=ide_bridge_client.js.map