"use strict";
/**
 * RSpade IDE Bridge - shared configuration helper
 *
 * Single source of truth for locating the RSpade project, resolving the IDE
 * bridge server URL, and reading the local-file grant token. Every IDE client
 * (bridge client, formatter, git status/diff) routes through here so there is
 * exactly ONE copy of this logic.
 *
 * SERVER URL: read APP_URL from the project-root .env. The literal sentinel
 * `APP_URL=https://$HOSTNAME` (unquoted, no braces) is expanded via os.hostname(),
 * matching the PHP side (gethostname()). APP_URL already carries the scheme.
 *
 * AUTH: the framework (dev only) writes a mode-restricted ide-grant-<random>.token
 * file into storage/rsx-ide-bridge/ (outside the web docroot). The client
 * reads that file from local disk and presents its trimmed contents in the
 * X-Ide-Token request header. Possession of the file IS the grant.
 *
 * FAIL LOUD: every resolver throws a descriptive Error when it cannot resolve.
 * There are NO silent fallbacks.
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
exports.readIdeToken = exports.resolveServerUrl = exports.find_rspade_root = void 0;
const vscode = __importStar(require("vscode"));
const os = __importStar(require("os"));
const path = __importStar(require("path"));
const fs = __importStar(require("fs"));
/**
 * Locate the RSpade project root: the workspace folder that contains
 * system/app/RSpade (current layout) or app/RSpade (legacy layout).
 */
function find_rspade_root() {
    if (!vscode.workspace.workspaceFolders) {
        return undefined;
    }
    for (const folder of vscode.workspace.workspaceFolders) {
        // Current structure: <root>/system/app/RSpade
        if (fs.existsSync(path.join(folder.uri.fsPath, 'system', 'app', 'RSpade'))) {
            return folder.uri.fsPath;
        }
        // Legacy structure: <root>/app/RSpade
        if (fs.existsSync(path.join(folder.uri.fsPath, 'app', 'RSpade'))) {
            return folder.uri.fsPath;
        }
    }
    return undefined;
}
exports.find_rspade_root = find_rspade_root;
/**
 * Resolve the IDE bridge server URL from <rspadeRoot>/.env APP_URL.
 * Expands a literal $HOSTNAME sentinel via os.hostname(). Optional override:
 * VS Code setting `rspade.serverUrl`. Returns the URL with no trailing slash.
 * Throws (fail loud) when .env is missing or APP_URL is undefined/empty.
 */
function resolveServerUrl(rspadeRoot) {
    // Optional explicit override for non-standard setups.
    const configured = vscode.workspace.getConfiguration('rspade').get('serverUrl');
    if (configured && configured.trim() !== '') {
        return configured.trim().replace(/\/+$/, '');
    }
    const env_file = path.join(rspadeRoot, '.env');
    if (!fs.existsSync(env_file)) {
        throw new Error(`RSpade IDE bridge: .env not found at ${env_file} - cannot resolve APP_URL.`);
    }
    const env_content = fs.readFileSync(env_file, 'utf8');
    let app_url = null;
    // phpdotenv semantics: first definition wins.
    for (const raw_line of env_content.split(/\r?\n/)) {
        const line = raw_line.trim();
        if (line.startsWith('#') || !line.startsWith('APP_URL=')) {
            continue;
        }
        let value = line.substring('APP_URL='.length).trim();
        if ((value.startsWith('"') && value.endsWith('"')) ||
            (value.startsWith("'") && value.endsWith("'"))) {
            value = value.substring(1, value.length - 1);
        }
        app_url = value;
        break;
    }
    if (app_url === null || app_url === '') {
        throw new Error(`RSpade IDE bridge: APP_URL is undefined or empty in ${env_file}.`);
    }
    // Expand the $HOSTNAME sentinel (matches PHP gethostname()).
    app_url = app_url.split('$HOSTNAME').join(os.hostname());
    if (app_url === '') {
        throw new Error(`RSpade IDE bridge: APP_URL resolved to empty after $HOSTNAME expansion in ${env_file}.`);
    }
    return app_url.replace(/\/+$/, '');
}
exports.resolveServerUrl = resolveServerUrl;
/**
 * Read the local-file grant token. Globs
 * <rspadeRoot>/storage/rsx-ide-bridge/ide-grant-*.token, reads and trims the
 * content, and returns it. When multiple exist, the newest by mtime is used.
 * Throws (fail loud) when the directory or a token file is missing/empty.
 *
 * Volatile storage was relocated out of system/ to the project root; the historic
 * system/storage location is still probed as a fallback for an environment that
 * has not yet run the relocation.
 */
function readIdeToken(rspadeRoot) {
    const relocated_dir = path.join(rspadeRoot, 'storage', 'rsx-ide-bridge');
    const legacy_dir = path.join(rspadeRoot, 'system', 'storage', 'rsx-ide-bridge');
    const bridge_dir = fs.existsSync(relocated_dir) ? relocated_dir : legacy_dir;
    let entries;
    try {
        entries = fs.readdirSync(bridge_dir);
    }
    catch (e) {
        throw new Error(`RSpade IDE bridge: token directory not found at ${bridge_dir}. Load the site in a browser (dev mode) to mint the grant token.`);
    }
    const token_files = entries
        .filter(name => name.startsWith('ide-grant-') && name.endsWith('.token'))
        .map(name => path.join(bridge_dir, name));
    if (token_files.length === 0) {
        throw new Error(`RSpade IDE bridge: no ide-grant-*.token found in ${bridge_dir}. Load the site in a browser (dev mode) to mint the grant token.`);
    }
    // Deterministically pick the newest token when more than one is present.
    let chosen = token_files[0];
    if (token_files.length > 1) {
        let newest_mtime = fs.statSync(chosen).mtimeMs;
        for (const candidate of token_files.slice(1)) {
            const mtime = fs.statSync(candidate).mtimeMs;
            if (mtime > newest_mtime) {
                newest_mtime = mtime;
                chosen = candidate;
            }
        }
    }
    const token = fs.readFileSync(chosen, 'utf8').trim();
    if (token === '') {
        throw new Error(`RSpade IDE bridge: grant token file ${chosen} is empty.`);
    }
    return token;
}
exports.readIdeToken = readIdeToken;
//# sourceMappingURL=ide_bridge_config.js.map