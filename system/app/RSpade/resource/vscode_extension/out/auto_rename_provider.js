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
exports.AutoRenameProvider = void 0;
const vscode = __importStar(require("vscode"));
const path = __importStar(require("path"));
const fs = __importStar(require("fs"));
const ide_bridge_client_1 = require("./ide_bridge_client");
/**
 * Provides automatic file renaming based on RSX naming conventions
 *
 * This provider watches for file saves in ./rsx directory and automatically
 * renames files to match their class names, @rsx_id, or <Define:> tags
 * according to RSX framework conventions.
 *
 * Only runs if auto_rename_files is set to true in config/rsx.php or rsx/resource/config/rsx.php
 * (user config takes precedence over framework config).
 * Files containing @FILENAME-CONVENTION-EXCEPTION are skipped.
 */
class AutoRenameProvider {
    constructor() {
        this.config_enabled = false;
        this.workspace_root = '';
        this.is_checking = false;
        this.ide_bridge_client = null;
        this.init();
    }
    find_rspade_root() {
        const workspace_folders = vscode.workspace.workspaceFolders;
        if (!workspace_folders) {
            return undefined;
        }
        // Check each workspace folder for app/RSpade/
        for (const folder of workspace_folders) {
            const app_rspade = path.join(folder.uri.fsPath, 'app', 'RSpade');
            if (fs.existsSync(app_rspade)) {
                return folder.uri.fsPath;
            }
        }
        return undefined;
    }
    async init() {
        const rspade_root = this.find_rspade_root();
        if (!rspade_root) {
            return;
        }
        this.workspace_root = rspade_root;
        await this.load_config();
    }
    async load_config() {
        const framework_config_path = path.join(this.workspace_root, 'config', 'rsx.php');
        const user_config_path = path.join(this.workspace_root, 'rsx', 'resource', 'config', 'rsx.php');
        let framework_enabled = false;
        let user_enabled = null;
        // Load framework config
        if (fs.existsSync(framework_config_path)) {
            try {
                const content = fs.readFileSync(framework_config_path, 'utf8');
                const value = this.extract_auto_rename_value(content);
                if (value !== null) {
                    framework_enabled = value;
                    console.log('[AutoRename] Framework config - auto_rename_files:', framework_enabled);
                }
            }
            catch (error) {
                console.error('[AutoRename] Failed to load framework config:', error);
            }
        }
        else {
            console.log('[AutoRename] Framework config file not found:', framework_config_path);
        }
        // Load user config (takes precedence)
        if (fs.existsSync(user_config_path)) {
            try {
                const content = fs.readFileSync(user_config_path, 'utf8');
                const value = this.extract_auto_rename_value(content);
                if (value !== null) {
                    user_enabled = value;
                    console.log('[AutoRename] User config - auto_rename_files:', user_enabled);
                }
            }
            catch (error) {
                console.error('[AutoRename] Failed to load user config:', error);
            }
        }
        else {
            console.log('[AutoRename] User config file not found:', user_config_path);
        }
        // User config takes precedence over framework config
        this.config_enabled = user_enabled !== null ? user_enabled : framework_enabled;
        console.log('[AutoRename] Final config - auto_rename_files:', this.config_enabled);
    }
    extract_auto_rename_value(content) {
        // Look for development.auto_rename_files setting
        // Match pattern: 'development' => [ ... 'auto_rename_files' => true/false ... ]
        const development_section_match = content.match(/'development'\s*=>\s*\[([\s\S]*?)\],\s*\/\*/);
        if (development_section_match) {
            const development_content = development_section_match[1];
            const auto_rename_match = development_content.match(/'auto_rename_files'\s*=>\s*(true|false)/);
            if (auto_rename_match) {
                return auto_rename_match[1] === 'true';
            }
        }
        return null;
    }
    activate(context) {
        console.log('[AutoRename] Provider activated');
        // Watch for completed file saves (not before save)
        context.subscriptions.push(vscode.workspace.onDidSaveTextDocument(async (document) => {
            if (this.is_checking) {
                console.log('[AutoRename] Already checking, skipping');
                return; // Prevent recursive calls
            }
            // Only process if this document is currently active in the editor
            const active_editor = vscode.window.activeTextEditor;
            if (!active_editor || active_editor.document !== document) {
                console.log('[AutoRename] Document not active, skipping (likely bulk save/replace)');
                return;
            }
            await this.load_config(); // Reload config on each save
            if (!this.config_enabled) {
                console.log('[AutoRename] Feature disabled in config');
                return;
            }
            const file_path = document.uri.fsPath;
            console.log('[AutoRename] File saved:', file_path);
            // Only process files in ./rsx directory
            const relative_path = path.relative(this.workspace_root, file_path);
            console.log('[AutoRename] Relative path:', relative_path);
            if (!relative_path.startsWith('rsx/') && !relative_path.startsWith('rsx\\')) {
                console.log('[AutoRename] Not in rsx/ directory, skipping');
                return;
            }
            // Check for exception marker
            const content = document.getText();
            if (content.includes('@FILENAME-CONVENTION-EXCEPTION')) {
                console.log('[AutoRename] File contains @FILENAME-CONVENTION-EXCEPTION, skipping');
                return;
            }
            this.is_checking = true;
            try {
                console.log('[AutoRename] Starting rename check...');
                await this.check_and_rename(document);
            }
            finally {
                this.is_checking = false;
            }
        }));
    }
    async check_and_rename(document) {
        const file_path = document.uri.fsPath;
        const extension = this.get_extension(file_path);
        const content = document.getText();
        console.log('[AutoRename] Extension detected:', extension);
        let identifier = null;
        let suggested_filename = null;
        // Determine identifier based on file type
        if (extension === 'php') {
            identifier = this.extract_php_class(content);
            console.log('[AutoRename] PHP class extracted:', identifier);
            if (identifier) {
                suggested_filename = await this.get_suggested_php_filename(file_path, identifier);
            }
        }
        else if (extension === 'js') {
            identifier = this.extract_js_class(content);
            console.log('[AutoRename] JS class extracted:', identifier);
            if (identifier) {
                suggested_filename = await this.get_suggested_js_filename(file_path, identifier, content);
            }
        }
        else if (extension === 'blade.php') {
            identifier = this.extract_rsx_id(content);
            console.log('[AutoRename] Blade @rsx_id extracted:', identifier);
            if (identifier) {
                suggested_filename = await this.get_suggested_blade_filename(file_path, identifier);
            }
        }
        else if (extension === 'jqhtml') {
            identifier = this.extract_jqhtml_component(content);
            console.log('[AutoRename] Jqhtml component extracted:', identifier);
            if (identifier) {
                suggested_filename = await this.get_suggested_jqhtml_filename(file_path, identifier);
            }
        }
        console.log('[AutoRename] Identifier:', identifier);
        console.log('[AutoRename] Suggested filename:', suggested_filename);
        if (!identifier || !suggested_filename) {
            console.log('[AutoRename] No identifier or suggested filename, skipping');
            return;
        }
        const current_filename = path.basename(file_path);
        console.log('[AutoRename] Current filename:', current_filename);
        if (current_filename.toLowerCase() === suggested_filename.toLowerCase()) {
            console.log('[AutoRename] Filename already correct (case-insensitive match)');
            return; // Already correct
        }
        // Check if current filename is already a valid short name
        // (i.e., a valid suffix of the identifier with 2+ segments and directory path matches prefix)
        if (this.is_valid_short_name(file_path, current_filename, identifier, extension)) {
            console.log('[AutoRename] Current filename is already a valid short name, skipping');
            return;
        }
        // Check if suggested filename already exists
        const dir = path.dirname(file_path);
        const new_path = path.join(dir, suggested_filename);
        if (fs.existsSync(new_path)) {
            console.log('[AutoRename] Cannot rename: ${suggested_filename} already exists at', new_path);
            return;
        }
        // Perform rename
        console.log('[AutoRename] Performing rename to:', suggested_filename);
        await this.rename_file(file_path, new_path);
    }
    async rename_file(old_path, new_path) {
        const old_uri = vscode.Uri.file(old_path);
        const new_uri = vscode.Uri.file(new_path);
        console.log('[AutoRename] Renaming from:', old_path);
        console.log('[AutoRename] Renaming to:', new_path);
        try {
            // Capture cursor position and view column before closing
            let cursor_position;
            let view_column;
            const old_doc = vscode.workspace.textDocuments.find(doc => doc.uri.fsPath === old_path);
            if (old_doc) {
                // Find the editor for this document to capture cursor position
                const old_editor = vscode.window.visibleTextEditors.find(editor => editor.document.uri.fsPath === old_path);
                if (old_editor) {
                    cursor_position = old_editor.selection.active;
                    view_column = old_editor.viewColumn;
                    console.log('[AutoRename] Captured cursor position:', cursor_position.line, cursor_position.character);
                    console.log('[AutoRename] Captured view column:', view_column);
                }
                console.log('[AutoRename] Closing old document');
                await vscode.window.showTextDocument(old_doc, { preview: false, preserveFocus: false });
                await vscode.commands.executeCommand('workbench.action.closeActiveEditor');
            }
            // Use VS Code's rename API for proper integration
            const edit = new vscode.WorkspaceEdit();
            edit.renameFile(old_uri, new_uri, { overwrite: false });
            const success = await vscode.workspace.applyEdit(edit);
            console.log('[AutoRename] Rename result:', success);
            if (success) {
                console.log(`[AutoRename] ✅ Auto-renamed: ${path.basename(old_path)} → ${path.basename(new_path)}`);
                // Wait a bit for the file system to settle
                await new Promise(resolve => setTimeout(resolve, 100));
                // Open the renamed file
                const new_document = await vscode.workspace.openTextDocument(new_uri);
                console.log('[AutoRename] Opened renamed document');
                // Show the document in the editor, restoring view column if we had one
                const show_options = {
                    preview: false,
                    viewColumn: view_column
                };
                const editor = await vscode.window.showTextDocument(new_document, show_options);
                console.log('[AutoRename] Showing renamed document in editor');
                // Restore cursor position if we captured one
                if (cursor_position) {
                    editor.selection = new vscode.Selection(cursor_position, cursor_position);
                    editor.revealRange(new vscode.Range(cursor_position, cursor_position));
                    console.log('[AutoRename] Restored cursor position');
                }
                // Format the document
                console.log('[AutoRename] Formatting document...');
                await vscode.commands.executeCommand('editor.action.formatDocument');
                console.log('[AutoRename] Format command executed');
                // Save the formatted document
                await new_document.save();
                console.log('[AutoRename] Saved formatted document');
            }
        }
        catch (error) {
            console.error('[AutoRename] ❌ Failed to rename file:', error);
        }
    }
    get_extension(file_path) {
        if (file_path.endsWith('.blade.php')) {
            return 'blade.php';
        }
        if (file_path.endsWith('.jqhtml')) {
            return 'jqhtml';
        }
        return path.extname(file_path).substring(1);
    }
    extract_php_class(content) {
        // Match: class ClassName
        const match = content.match(/^\s*class\s+([A-Za-z0-9_]+)/m);
        return match ? match[1] : null;
    }
    extract_js_class(content) {
        // Match: class ClassName
        const match = content.match(/^\s*class\s+([A-Za-z0-9_]+)/m);
        return match ? match[1] : null;
    }
    extract_rsx_id(content) {
        // Match: @rsx_id('identifier')
        const match = content.match(/@rsx_id\s*\(\s*['"]([^'"]+)['"]\s*\)/);
        return match ? match[1] : null;
    }
    extract_jqhtml_component(content) {
        // Match: <Define:ComponentName>
        const match = content.match(/<Define:([A-Za-z0-9_]+)>/);
        return match ? match[1] : null;
    }
    async get_suggested_php_filename(file_path, class_name) {
        // rsx/ files use lowercase convention
        const dir = path.dirname(file_path);
        const relative_dir = path.relative(this.workspace_root, dir);
        const short_name = this.extract_short_name(class_name, relative_dir);
        if (short_name) {
            return short_name.toLowerCase() + '.php';
        }
        return class_name.toLowerCase() + '.php';
    }
    async get_suggested_js_filename(file_path, class_name, content) {
        // Check if this extends Jqhtml_Component (directly or via inheritance)
        let is_jqhtml = content.includes('extends Jqhtml_Component');
        // If not directly extending, check via API
        if (!is_jqhtml && content.includes('extends ')) {
            // Initialize IDE bridge client if needed
            if (!this.ide_bridge_client) {
                const output_channel = vscode.window.createOutputChannel('RSpade Auto Rename');
                this.ide_bridge_client = new ide_bridge_client_1.IdeBridgeClient(output_channel);
            }
            try {
                const response = await this.ide_bridge_client.js_is_subclass_of(class_name, 'Jqhtml_Component');
                is_jqhtml = response.is_subclass || false;
            }
            catch (error) {
                // If API call fails, fall back to direct check only
                console.log('[AutoRename] JS - Failed to check inheritance:', error);
            }
        }
        console.log('[AutoRename] JS - Is Jqhtml_Component:', is_jqhtml);
        const dir = path.dirname(file_path);
        const relative_dir = path.relative(this.workspace_root, dir);
        console.log('[AutoRename] JS - Relative directory:', relative_dir);
        if (is_jqhtml) {
            // For Jqhtml components, use snake_case convention
            const snake_case = this.pascal_to_snake_case(class_name);
            console.log('[AutoRename] JS - PascalCase to snake_case:', class_name, '→', snake_case);
            const short_name = this.extract_short_name(class_name, relative_dir);
            console.log('[AutoRename] JS - Short name extracted:', short_name);
            if (short_name) {
                const short_snake = this.pascal_to_snake_case(short_name);
                console.log('[AutoRename] JS - Short name to snake_case:', short_name, '→', short_snake);
                return short_snake.toLowerCase() + '.js';
            }
            return snake_case.toLowerCase() + '.js';
        }
        else {
            // Regular JS classes use lowercase
            const short_name = this.extract_short_name(class_name, relative_dir);
            console.log('[AutoRename] JS - Short name extracted:', short_name);
            if (short_name) {
                return short_name.toLowerCase() + '.js';
            }
            return class_name.toLowerCase() + '.js';
        }
    }
    async get_suggested_blade_filename(file_path, rsx_id) {
        const dir = path.dirname(file_path);
        const relative_dir = path.relative(this.workspace_root, dir);
        const short_name = this.extract_short_name(rsx_id, relative_dir);
        if (short_name) {
            return short_name.toLowerCase() + '.blade.php';
        }
        return rsx_id.toLowerCase() + '.blade.php';
    }
    async get_suggested_jqhtml_filename(file_path, component_name) {
        // Jqhtml components use snake_case convention
        const snake_case = this.pascal_to_snake_case(component_name);
        console.log('[AutoRename] JQHTML - PascalCase to snake_case:', component_name, '→', snake_case);
        const dir = path.dirname(file_path);
        const relative_dir = path.relative(this.workspace_root, dir);
        console.log('[AutoRename] JQHTML - Relative directory:', relative_dir);
        const short_name = this.extract_short_name(component_name, relative_dir);
        console.log('[AutoRename] JQHTML - Short name extracted:', short_name);
        if (short_name) {
            const short_snake = this.pascal_to_snake_case(short_name);
            console.log('[AutoRename] JQHTML - Short name to snake_case:', short_name, '→', short_snake);
            return short_snake.toLowerCase() + '.jqhtml';
        }
        return snake_case.toLowerCase() + '.jqhtml';
    }
    /**
     * Convert PascalCase to snake_case
     * Inserts underscores before uppercase letters and before first digit in number sequences
     * Example: TestComponent1 -> Test_Component_1
     */
    pascal_to_snake_case(name) {
        // Insert underscore before uppercase letters (except first character)
        let result = name.replace(/(?<!^)([A-Z])/g, '_$1');
        // Insert underscore before first digit in a run of digits
        result = result.replace(/(?<!^)(?<![0-9])([0-9])/g, '_$1');
        // Replace multiple consecutive underscores with single underscore
        result = result.replace(/_+/g, '_');
        return result;
    }
    /**
     * Check if current filename is already a valid short name
     *
     * A filename is a valid short name if:
     * - It matches a suffix of the identifier (class/id name)
     * - It has 2+ segments (when split by underscore)
     * - The match is case-insensitive
     */
    is_valid_short_name(file_path, current_filename, identifier, extension) {
        // Remove extension to get base name
        let current_base = current_filename;
        if (current_filename.endsWith('.' + extension)) {
            current_base = current_filename.substring(0, current_filename.length - extension.length - 1);
        }
        // Use extract_short_name to determine what the valid short name should be for this directory
        const dir_path = path.dirname(file_path);
        const valid_short_name = this.extract_short_name(identifier, dir_path);
        // If there's no valid short name according to the convention, current filename is not a valid short name
        if (!valid_short_name) {
            return false;
        }
        // Check if current base matches the valid short name (case-insensitive)
        if (current_base.toLowerCase() === valid_short_name.toLowerCase()) {
            console.log('[AutoRename] Validated as short name:', current_base, '(matches convention short name', valid_short_name + ')');
            return true;
        }
        return false;
    }
    /**
     * Extract valid short name(s) from class/id by checking if directory + filename parts match full name
     * Returns the filename parts as a valid short name if they can be combined with directory parts
     *
     * Rules:
     * - Short names only allowed in ./rsx directory (NOT in /app/RSpade)
     * - Original name must have 3+ segments for short name to be allowed (2-segment names must use full name)
     * - Short name must have 2+ segments (exception: if original was 1 segment, short can be 1 segment)
     * - Allows partial overlap between directory and filename (e.g., frontend/settings/account/ with settings_account filename)
     *
     * Algorithm: For a filename to be valid, there must exist a contiguous sequence in the directory path
     * that, when combined with the filename parts, equals the full name parts.
     */
    extract_short_name(full_name, dir_path) {
        // Short names only allowed in ./rsx directory, not in framework code (/app/RSpade)
        if (dir_path.includes('/app/RSpade') || dir_path.includes('\\app\\RSpade')) {
            return null;
        }
        // Split the full name by underscores
        const name_parts = full_name.split('_');
        const original_segment_count = name_parts.length;
        // If original name has exactly 2 segments, short name is NOT allowed
        if (original_segment_count === 2) {
            return null;
        }
        // If only 1 segment, no prefix to match
        if (original_segment_count === 1) {
            return null;
        }
        // Split directory path into parts (handle both / and \ separators)
        const dir_parts = dir_path.split(/[/\\]/).filter(p => p.length > 0);
        // Try all possible short name lengths (from longest to shortest, minimum 2 segments)
        // For each potential short name, check if directory contains the complementary prefix parts
        for (let short_len = original_segment_count - 1; short_len >= 2; short_len--) {
            const short_parts = name_parts.slice(original_segment_count - short_len);
            const prefix_parts = name_parts.slice(0, original_segment_count - short_len);
            // Check if prefix_parts exist as a contiguous sequence in directory
            const prefix_len = prefix_parts.length;
            for (let start_idx = 0; start_idx <= dir_parts.length - prefix_len; start_idx++) {
                let all_match = true;
                for (let i = 0; i < prefix_len; i++) {
                    if (dir_parts[start_idx + i].toLowerCase() !== prefix_parts[i].toLowerCase()) {
                        all_match = false;
                        break;
                    }
                }
                if (all_match) {
                    // Found valid prefix in directory, this short name is valid
                    return short_parts.join('_');
                }
            }
        }
        return null; // No valid short name found
    }
    dispose() {
        // Cleanup if needed
    }
}
exports.AutoRenameProvider = AutoRenameProvider;
//# sourceMappingURL=auto_rename_provider.js.map