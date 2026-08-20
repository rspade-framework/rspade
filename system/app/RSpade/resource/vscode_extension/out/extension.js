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
exports.deactivate = exports.activate = void 0;
const vscode = __importStar(require("vscode"));
const file_watcher_1 = require("./file_watcher");
const formatting_provider_1 = require("./formatting_provider");
const definition_provider_1 = require("./definition_provider");
const config_1 = require("./config");
const laravel_completion_provider_1 = require("./laravel_completion_provider");
const convention_method_provider_1 = require("./convention_method_provider");
const comment_file_reference_provider_1 = require("./comment_file_reference_provider");
const jqhtml_lifecycle_provider_1 = require("./jqhtml_lifecycle_provider");
const combined_semantic_provider_1 = require("./combined_semantic_provider");
const php_attribute_provider_1 = require("./php_attribute_provider");
const auto_rename_provider_1 = require("./auto_rename_provider");
const folder_color_provider_1 = require("./folder_color_provider");
const git_status_provider_1 = require("./git_status_provider");
const git_diff_provider_1 = require("./git_diff_provider");
const refactor_provider_1 = require("./refactor_provider");
const refactor_code_actions_1 = require("./refactor_code_actions");
const class_refactor_provider_1 = require("./class_refactor_provider");
const class_refactor_code_actions_1 = require("./class_refactor_code_actions");
const sort_class_methods_provider_1 = require("./sort_class_methods_provider");
const symlink_redirect_provider_1 = require("./symlink_redirect_provider");
const fs = __importStar(require("fs"));
const path = __importStar(require("path"));
let file_watcher;
let formatting_provider;
let definition_provider;
let laravel_completion_provider;
let auto_rename_provider;
/**
 * Check for conflicting PHP extensions and prompt user to disable them
 */
async function check_conflicting_extensions() {
    const intelephense = vscode.extensions.getExtension('bmewburn.vscode-intelephense-client');
    const php_intellisense = vscode.extensions.getExtension('zobo.php-intellisense');
    // Only warn if both Intelephense and PHP IntelliSense are installed
    if (intelephense && php_intellisense) {
        const action = await vscode.window.showWarningMessage(`Both "Intelephense" and "PHP IntelliSense" are installed. ` +
            `It is recommended to disable "PHP IntelliSense" to avoid conflicts.`, 'Disable PHP IntelliSense', 'Ignore');
        if (action === 'Disable PHP IntelliSense') {
            await vscode.commands.executeCommand('workbench.extensions.action.showExtensionsWithIds', [['zobo.php-intellisense']]);
        }
    }
}
/**
 * Find the RSpade project root folder (contains rsx/ and system/app/RSpade/)
 * Works in both single-folder and multi-root workspace modes
 */
function find_rspade_root() {
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
            console.log(`[RSpade] Found project root (new structure): ${folder.uri.fsPath}`);
            return folder.uri.fsPath;
        }
        // Legacy structure: just app/RSpade/
        const app_rspade = path.join(folder.uri.fsPath, 'app', 'RSpade');
        if (fs.existsSync(app_rspade)) {
            console.log(`[RSpade] Found project root (legacy structure): ${folder.uri.fsPath}`);
            return folder.uri.fsPath;
        }
    }
    return undefined;
}
async function activate(context) {
    console.log('RSpade Framework extension is now active');
    // Find RSpade project root
    const rspade_root = find_rspade_root();
    if (!rspade_root) {
        console.log('Not an RSpade project (no rsx/ and system/app/RSpade/ found), extension features disabled');
        return;
    }
    console.log(`[RSpade] Project root: ${rspade_root}`);
    // Get JQHTML extension API for component navigation
    // Try both possible extension IDs
    let jqhtml_api = undefined;
    const possible_jqhtml_ids = [
        'jqhtml.jqhtml-vscode-extension',
        'jqhtml.@jqhtml/vscode-extension',
        'jqhtml.jqhtml-language'
    ];
    console.log('[RSpade] Searching for JQHTML extension...');
    console.log('[RSpade] All installed extensions:', vscode.extensions.all.map(e => e.id).filter(id => id.includes('jqhtml')));
    let jqhtml_extension = null;
    for (const ext_id of possible_jqhtml_ids) {
        console.log(`[RSpade] Trying extension ID: ${ext_id}`);
        jqhtml_extension = vscode.extensions.getExtension(ext_id);
        if (jqhtml_extension) {
            console.log(`[RSpade] JQHTML extension found with ID: ${ext_id}`);
            console.log(`[RSpade] Extension isActive: ${jqhtml_extension.isActive}`);
            break;
        }
        else {
            console.log(`[RSpade] Extension ID not found: ${ext_id}`);
        }
    }
    if (!jqhtml_extension) {
        console.warn('[RSpade] JQHTML extension not found - component navigation in Blade files will be unavailable');
    }
    else {
        try {
            console.log('[RSpade] JQHTML extension isActive before activate():', jqhtml_extension.isActive);
            console.log('[RSpade] Calling activate() on JQHTML extension...');
            // Always call activate() - it returns the API or exports if already active
            jqhtml_api = await jqhtml_extension.activate();
            console.log('[RSpade] JQHTML extension isActive after activate():', jqhtml_extension.isActive);
            console.log('[RSpade] JQHTML extension API loaded successfully');
            console.log('[RSpade] API type:', typeof jqhtml_api);
            console.log('[RSpade] API value:', jqhtml_api);
            console.log('[RSpade] API methods:', Object.keys(jqhtml_api || {}));
            console.log('[RSpade] findComponent exists:', typeof (jqhtml_api && jqhtml_api.findComponent));
            console.log('[RSpade] getAllComponentNames exists:', typeof (jqhtml_api && jqhtml_api.getAllComponentNames));
            console.log('[RSpade] reindexWorkspace exists:', typeof (jqhtml_api && jqhtml_api.reindexWorkspace));
        }
        catch (error) {
            console.warn('[RSpade] JQHTML extension found but API could not be loaded:', error);
        }
    }
    // Initialize providers
    file_watcher = new file_watcher_1.RspadeFileWatcher();
    formatting_provider = new formatting_provider_1.RspadeFormattingProvider();
    definition_provider = new definition_provider_1.RspadeDefinitionProvider(jqhtml_api);
    laravel_completion_provider = new laravel_completion_provider_1.LaravelCompletionProvider();
    // Register folder color provider
    const folder_color_provider = new folder_color_provider_1.FolderColorProvider();
    context.subscriptions.push(vscode.window.registerFileDecorationProvider(folder_color_provider));
    // Register git status provider
    const git_status_provider = new git_status_provider_1.GitStatusProvider(rspade_root);
    context.subscriptions.push(vscode.window.registerFileDecorationProvider(git_status_provider));
    // Register git diff provider
    const git_diff_provider = new git_diff_provider_1.GitDiffProvider(rspade_root);
    git_diff_provider.activate(context);
    // Register symlink redirect provider
    const symlink_redirect_provider = new symlink_redirect_provider_1.SymlinkRedirectProvider();
    symlink_redirect_provider.activate(context);
    console.log('Symlink redirect provider registered - system/rsx/ files will redirect to rsx/');
    // Register refactor provider
    const refactor_provider = new refactor_provider_1.RspadeRefactorProvider(formatting_provider);
    refactor_provider.register(context);
    // Register refactor code actions provider
    const refactor_code_actions = new refactor_code_actions_1.RspadeRefactorCodeActionsProvider(refactor_provider);
    context.subscriptions.push(vscode.languages.registerCodeActionsProvider({ language: 'php' }, refactor_code_actions, {
        providedCodeActionKinds: [vscode.CodeActionKind.Refactor]
    }));
    // Register auto-rename provider early (needed by class refactor provider)
    auto_rename_provider = new auto_rename_provider_1.AutoRenameProvider();
    auto_rename_provider.activate(context);
    console.log('Auto-rename provider registered for rsx/ files');
    // Register class refactor provider
    const class_refactor_provider = new class_refactor_provider_1.RspadeClassRefactorProvider(formatting_provider, auto_rename_provider);
    class_refactor_provider.register(context);
    // Register class refactor code actions provider
    const class_refactor_code_actions = new class_refactor_code_actions_1.RspadeClassRefactorCodeActionsProvider(class_refactor_provider);
    context.subscriptions.push(vscode.languages.registerCodeActionsProvider({ language: 'php' }, class_refactor_code_actions, {
        providedCodeActionKinds: [vscode.CodeActionKind.Refactor]
    }));
    // Register sort class methods provider
    const sort_methods_provider = new sort_class_methods_provider_1.RspadeSortClassMethodsProvider(formatting_provider);
    sort_methods_provider.register(context);
    // Activate file watcher
    if ((0, config_1.get_config)().get('enableFormatOnMove', true)) {
        file_watcher.activate(context);
    }
    // Register formatting provider
    context.subscriptions.push(vscode.languages.registerDocumentFormattingEditProvider({ language: 'php' }, formatting_provider));
    console.log('RSpade formatter registered for PHP files');
    // Register definition provider for JavaScript/TypeScript and PHP/Blade/jqhtml files
    context.subscriptions.push(vscode.languages.registerDefinitionProvider([
        { language: 'javascript' },
        { language: 'typescript' },
        { language: 'php' },
        { language: 'blade' },
        { language: 'html' },
        { pattern: '**/*.jqhtml' },
        { pattern: '**/*.blade.php' }
    ], definition_provider));
    console.log('RSpade definition provider registered for JavaScript/TypeScript/PHP/Blade/jqhtml files');
    // Register Laravel completion provider for PHP files
    context.subscriptions.push(vscode.languages.registerCompletionItemProvider({ language: 'php' }, laravel_completion_provider));
    console.log('Laravel completion provider registered for PHP files');
    // Register convention method providers for JavaScript/TypeScript
    const convention_hover_provider = new convention_method_provider_1.ConventionMethodHoverProvider();
    const convention_diagnostic_provider = new convention_method_provider_1.ConventionMethodDiagnosticProvider();
    const convention_definition_provider = new convention_method_provider_1.ConventionMethodDefinitionProvider();
    context.subscriptions.push(vscode.languages.registerHoverProvider([{ language: 'javascript' }, { language: 'typescript' }], convention_hover_provider));
    context.subscriptions.push(vscode.languages.registerDefinitionProvider([{ language: 'javascript' }, { language: 'typescript' }], convention_definition_provider));
    convention_diagnostic_provider.activate(context);
    console.log('Convention method providers registered for JavaScript/TypeScript');
    // Register JQHTML lifecycle method providers for JavaScript/TypeScript
    const jqhtml_hover_provider = new jqhtml_lifecycle_provider_1.JqhtmlLifecycleHoverProvider();
    const jqhtml_diagnostic_provider = new jqhtml_lifecycle_provider_1.JqhtmlLifecycleDiagnosticProvider();
    context.subscriptions.push(vscode.languages.registerHoverProvider([{ language: 'javascript' }, { language: 'typescript' }], jqhtml_hover_provider));
    jqhtml_diagnostic_provider.activate(context);
    console.log('JQHTML lifecycle providers registered for JavaScript/TypeScript');
    // Register combined semantic tokens provider for JavaScript/TypeScript
    // This includes: JQHTML lifecycle methods (orange), file references (teal), 'that' variable (blue)
    const combined_semantic_provider = new combined_semantic_provider_1.CombinedSemanticTokensProvider();
    context.subscriptions.push(vscode.languages.registerDocumentSemanticTokensProvider([{ language: 'javascript' }, { language: 'typescript' }], combined_semantic_provider, new vscode.SemanticTokensLegend(['conventionMethod', 'class', 'macro'])));
    console.log('Combined semantic tokens provider registered (JQHTML lifecycle, file references, that variable)');
    // Register comment file reference definition provider for JavaScript/TypeScript
    const comment_file_reference_definition_provider = new comment_file_reference_provider_1.CommentFileReferenceDefinitionProvider();
    context.subscriptions.push(vscode.languages.registerDefinitionProvider([{ language: 'javascript' }, { language: 'typescript' }], comment_file_reference_definition_provider));
    console.log('Comment file reference definition provider registered for JavaScript/TypeScript');
    // Register PHP attribute provider
    const php_attribute_provider = new php_attribute_provider_1.PhpAttributeSemanticTokensProvider();
    context.subscriptions.push(vscode.languages.registerDocumentSemanticTokensProvider([{ language: 'php' }], php_attribute_provider, new vscode.SemanticTokensLegend(['conventionMethod'])));
    console.log('PHP attribute provider registered for PHP files');
    // Clear status bar on document save
    context.subscriptions.push(vscode.workspace.onDidSaveTextDocument(() => {
        definition_provider.clear_status_bar();
    }));
    // Register commands
    context.subscriptions.push(vscode.commands.registerCommand('rspade.formatPhpFile', async () => {
        const editor = vscode.window.activeTextEditor;
        if (editor && editor.document.languageId === 'php') {
            await vscode.commands.executeCommand('editor.action.formatDocument');
        }
    }));
    context.subscriptions.push(vscode.commands.registerCommand('rspade.updateNamespace', async () => {
        const editor = vscode.window.activeTextEditor;
        if (editor && editor.document.languageId === 'php') {
            await formatting_provider.update_namespace_only(editor.document);
        }
    }));
    // Override built-in copyRelativePath commands to use project root
    const copy_relative_path_handler = async (uri) => {
        const rspade_root = find_rspade_root();
        if (!rspade_root) {
            vscode.window.showErrorMessage('Could not find RSpade project root');
            return;
        }
        // Get URI from context menu click or active editor
        const file_uri = uri || vscode.window.activeTextEditor?.document.uri;
        if (!file_uri) {
            return;
        }
        // Get path relative to project root
        const relative_path = path.relative(rspade_root, file_uri.fsPath);
        // Copy to clipboard
        await vscode.env.clipboard.writeText(relative_path);
        vscode.window.showInformationMessage(`Copied: ${relative_path}`);
    };
    // Register our custom command
    context.subscriptions.push(vscode.commands.registerCommand('rspade.copyRelativePathFromRoot', copy_relative_path_handler));
    // Override built-in commands
    context.subscriptions.push(vscode.commands.registerCommand('copyRelativePath', copy_relative_path_handler));
    context.subscriptions.push(vscode.commands.registerCommand('copyRelativeFilePath', copy_relative_path_handler));
    // Watch for configuration changes
    context.subscriptions.push(vscode.workspace.onDidChangeConfiguration(e => {
        if (e.affectsConfiguration('rspade')) {
            vscode.window.showInformationMessage('RSpade configuration changed. Restart VS Code for some changes to take effect.');
        }
    }));
    // Watch for extension update marker file
    watch_for_self_update(context);
    // Watch for terminal close marker file
    watch_for_terminal_close(context);
}
exports.activate = activate;
function watch_for_self_update(context) {
    // Check for update marker file every 2 seconds
    const rspade_root = find_rspade_root();
    if (!rspade_root) {
        return;
    }
    const marker_file = path.join(rspade_root, '.vscode', '.rspade-extension-updated');
    const check_interval = setInterval(() => {
        if (fs.existsSync(marker_file)) {
            console.log('[RSpade] Extension update marker detected, reloading window in 2 seconds...');
            // Clear the interval immediately
            clearInterval(check_interval);
            // Wait 2 seconds before reloading to allow other VS Code instances to see the marker
            setTimeout(async () => {
                // Try to delete the marker file (may already be deleted by another instance)
                try {
                    if (fs.existsSync(marker_file)) {
                        fs.unlinkSync(marker_file);
                        console.log('[RSpade] Deleted marker file');
                    }
                }
                catch (error) {
                    console.error('[RSpade] Failed to delete marker file:', error);
                }
                // Close the terminal panel
                console.log('[RSpade] Closing terminal panel');
                await vscode.commands.executeCommand('workbench.action.closePanel');
                // Wait 200ms for panel to close
                await new Promise(resolve => setTimeout(resolve, 200));
                // Check for conflicting extensions after panel closes
                await check_conflicting_extensions();
                // Auto-reload VS Code
                console.log('[RSpade] Reloading window now');
                vscode.commands.executeCommand('workbench.action.reloadWindow');
            }, 2000);
        }
    }, 2000); // Check every 2 seconds
    // Clean up interval on deactivate
    context.subscriptions.push({
        dispose: () => clearInterval(check_interval)
    });
}
function watch_for_terminal_close(context) {
    // Check for terminal close marker file every second
    const rspade_root = find_rspade_root();
    if (!rspade_root) {
        return;
    }
    const marker_file = path.join(rspade_root, '.vscode', '.rspade-close-terminal');
    const check_interval = setInterval(() => {
        if (fs.existsSync(marker_file)) {
            console.log('[RSpade] Terminal close marker detected, hiding panel in 2 seconds...');
            // Clear the interval immediately
            clearInterval(check_interval);
            // Wait 2 seconds to allow other VS Code instances to see the marker
            setTimeout(async () => {
                // Try to delete the marker file (may already be deleted by another instance)
                try {
                    if (fs.existsSync(marker_file)) {
                        fs.unlinkSync(marker_file);
                        console.log('[RSpade] Deleted terminal close marker');
                    }
                }
                catch (error) {
                    console.error('[RSpade] Failed to delete terminal close marker:', error);
                }
                // Close all terminals
                console.log('[RSpade] Closing all terminals');
                vscode.window.terminals.forEach(terminal => terminal.dispose());
                // Close the terminal panel
                console.log('[RSpade] Closing terminal panel');
                await vscode.commands.executeCommand('workbench.action.closePanel');
            }, 2000);
        }
    }, 1000); // Check every second
    // Clean up interval on deactivate
    context.subscriptions.push({
        dispose: () => clearInterval(check_interval)
    });
}
function deactivate() {
    // Cleanup
    if (file_watcher) {
        file_watcher.dispose();
    }
    if (auto_rename_provider) {
        auto_rename_provider.dispose();
    }
}
exports.deactivate = deactivate;
//# sourceMappingURL=extension.js.map