import * as vscode from 'vscode';
import { RspadeFileWatcher } from './file_watcher';
import { RspadeFormattingProvider } from './formatting_provider';
import { RspadeDefinitionProvider } from './definition_provider';
import { get_config } from './config';
import { LaravelCompletionProvider } from './laravel_completion_provider';
import { ConventionMethodHoverProvider, ConventionMethodDiagnosticProvider, ConventionMethodDefinitionProvider } from './convention_method_provider';
import { CommentFileReferenceDefinitionProvider } from './comment_file_reference_provider';
import { JqhtmlLifecycleHoverProvider, JqhtmlLifecycleDiagnosticProvider } from './jqhtml_lifecycle_provider';
import { CombinedSemanticTokensProvider } from './combined_semantic_provider';
import { PhpAttributeSemanticTokensProvider } from './php_attribute_provider';
import { AutoRenameProvider } from './auto_rename_provider';
import { FolderColorProvider } from './folder_color_provider';
import { GitStatusProvider } from './git_status_provider';
import { GitDiffProvider } from './git_diff_provider';
import { RspadeRefactorProvider } from './refactor_provider';
import { RspadeRefactorCodeActionsProvider } from './refactor_code_actions';
import { RspadeClassRefactorProvider } from './class_refactor_provider';
import { RspadeClassRefactorCodeActionsProvider } from './class_refactor_code_actions';
import { RspadeSortClassMethodsProvider } from './sort_class_methods_provider';
import { SymlinkRedirectProvider } from './symlink_redirect_provider';
import * as fs from 'fs';
import * as path from 'path';

let file_watcher: RspadeFileWatcher;
let formatting_provider: RspadeFormattingProvider;
let definition_provider: RspadeDefinitionProvider;
let laravel_completion_provider: LaravelCompletionProvider;
let auto_rename_provider: AutoRenameProvider;

/**
 * Check for conflicting PHP extensions and prompt user to disable them
 */
async function check_conflicting_extensions() {
    const intelephense = vscode.extensions.getExtension('bmewburn.vscode-intelephense-client');
    const php_intellisense = vscode.extensions.getExtension('zobo.php-intellisense');

    // Only warn if both Intelephense and PHP IntelliSense are installed
    if (intelephense && php_intellisense) {
        const action = await vscode.window.showWarningMessage(
            `Both "Intelephense" and "PHP IntelliSense" are installed. ` +
            `It is recommended to disable "PHP IntelliSense" to avoid conflicts.`,
            'Disable PHP IntelliSense',
            'Ignore'
        );

        if (action === 'Disable PHP IntelliSense') {
            await vscode.commands.executeCommand('workbench.extensions.action.showExtensionsWithIds', [['zobo.php-intellisense']]);
        }
    }
}

/**
 * Find the RSpade project root folder (contains rsx/ and system/app/RSpade/)
 * Works in both single-folder and multi-root workspace modes
 */
function find_rspade_root(): string | undefined {
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

export async function activate(context: vscode.ExtensionContext) {
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
        } else {
            console.log(`[RSpade] Extension ID not found: ${ext_id}`);
        }
    }

    if (!jqhtml_extension) {
        console.warn('[RSpade] JQHTML extension not found - component navigation in Blade files will be unavailable');
    } else {
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
        } catch (error) {
            console.warn('[RSpade] JQHTML extension found but API could not be loaded:', error);
        }
    }

    // Initialize providers
    file_watcher = new RspadeFileWatcher();
    formatting_provider = new RspadeFormattingProvider();
    definition_provider = new RspadeDefinitionProvider(jqhtml_api);
    laravel_completion_provider = new LaravelCompletionProvider();

    // Register folder color provider
    const folder_color_provider = new FolderColorProvider();
    context.subscriptions.push(
        vscode.window.registerFileDecorationProvider(folder_color_provider)
    );

    // Register git status provider
    const git_status_provider = new GitStatusProvider(rspade_root);
    context.subscriptions.push(
        vscode.window.registerFileDecorationProvider(git_status_provider)
    );

    // Register git diff provider
    const git_diff_provider = new GitDiffProvider(rspade_root);
    git_diff_provider.activate(context);

    // Register symlink redirect provider
    const symlink_redirect_provider = new SymlinkRedirectProvider();
    symlink_redirect_provider.activate(context);
    console.log('Symlink redirect provider registered - system/rsx/ files will redirect to rsx/');

    // Register refactor provider
    const refactor_provider = new RspadeRefactorProvider(formatting_provider);
    refactor_provider.register(context);

    // Register refactor code actions provider
    const refactor_code_actions = new RspadeRefactorCodeActionsProvider(refactor_provider);
    context.subscriptions.push(
        vscode.languages.registerCodeActionsProvider(
            { language: 'php' },
            refactor_code_actions,
            {
                providedCodeActionKinds: [vscode.CodeActionKind.Refactor]
            }
        )
    );

    // Register auto-rename provider early (needed by class refactor provider)
    auto_rename_provider = new AutoRenameProvider();
    auto_rename_provider.activate(context);
    console.log('Auto-rename provider registered for rsx/ files');

    // Register class refactor provider
    const class_refactor_provider = new RspadeClassRefactorProvider(formatting_provider, auto_rename_provider);
    class_refactor_provider.register(context);

    // Register class refactor code actions provider
    const class_refactor_code_actions = new RspadeClassRefactorCodeActionsProvider(class_refactor_provider);
    context.subscriptions.push(
        vscode.languages.registerCodeActionsProvider(
            { language: 'php' },
            class_refactor_code_actions,
            {
                providedCodeActionKinds: [vscode.CodeActionKind.Refactor]
            }
        )
    );

    // Register sort class methods provider
    const sort_methods_provider = new RspadeSortClassMethodsProvider(formatting_provider);
    sort_methods_provider.register(context);

    // Activate file watcher
    if (get_config().get<boolean>('enableFormatOnMove', true)) {
        file_watcher.activate(context);
    }

    // Register formatting provider
    context.subscriptions.push(
        vscode.languages.registerDocumentFormattingEditProvider(
            { language: 'php' },
            formatting_provider
        )
    );

    console.log('RSpade formatter registered for PHP files');

    // Register definition provider for JavaScript/TypeScript and PHP/Blade/jqhtml files
    context.subscriptions.push(
        vscode.languages.registerDefinitionProvider(
            [
                { language: 'javascript' },
                { language: 'typescript' },
                { language: 'php' },
                { language: 'blade' },
                { language: 'html' },
                { pattern: '**/*.jqhtml' },
                { pattern: '**/*.blade.php' }
            ],
            definition_provider
        )
    );

    console.log('RSpade definition provider registered for JavaScript/TypeScript/PHP/Blade/jqhtml files');

    // Register Laravel completion provider for PHP files
    context.subscriptions.push(
        vscode.languages.registerCompletionItemProvider(
            { language: 'php' },
            laravel_completion_provider
        )
    );

    console.log('Laravel completion provider registered for PHP files');

    // Register convention method providers for JavaScript/TypeScript
    const convention_hover_provider = new ConventionMethodHoverProvider();
    const convention_diagnostic_provider = new ConventionMethodDiagnosticProvider();
    const convention_definition_provider = new ConventionMethodDefinitionProvider();

    context.subscriptions.push(
        vscode.languages.registerHoverProvider(
            [{ language: 'javascript' }, { language: 'typescript' }],
            convention_hover_provider
        )
    );

    context.subscriptions.push(
        vscode.languages.registerDefinitionProvider(
            [{ language: 'javascript' }, { language: 'typescript' }],
            convention_definition_provider
        )
    );

    convention_diagnostic_provider.activate(context);

    console.log('Convention method providers registered for JavaScript/TypeScript');

    // Register JQHTML lifecycle method providers for JavaScript/TypeScript
    const jqhtml_hover_provider = new JqhtmlLifecycleHoverProvider();
    const jqhtml_diagnostic_provider = new JqhtmlLifecycleDiagnosticProvider();

    context.subscriptions.push(
        vscode.languages.registerHoverProvider(
            [{ language: 'javascript' }, { language: 'typescript' }],
            jqhtml_hover_provider
        )
    );

    jqhtml_diagnostic_provider.activate(context);

    console.log('JQHTML lifecycle providers registered for JavaScript/TypeScript');

    // Register combined semantic tokens provider for JavaScript/TypeScript
    // This includes: JQHTML lifecycle methods (orange), file references (teal), 'that' variable (blue)
    const combined_semantic_provider = new CombinedSemanticTokensProvider();

    context.subscriptions.push(
        vscode.languages.registerDocumentSemanticTokensProvider(
            [{ language: 'javascript' }, { language: 'typescript' }],
            combined_semantic_provider,
            new vscode.SemanticTokensLegend(['conventionMethod', 'class', 'macro'])
        )
    );

    console.log('Combined semantic tokens provider registered (JQHTML lifecycle, file references, that variable)');

    // Register comment file reference definition provider for JavaScript/TypeScript
    const comment_file_reference_definition_provider = new CommentFileReferenceDefinitionProvider();

    context.subscriptions.push(
        vscode.languages.registerDefinitionProvider(
            [{ language: 'javascript' }, { language: 'typescript' }],
            comment_file_reference_definition_provider
        )
    );

    console.log('Comment file reference definition provider registered for JavaScript/TypeScript');

    // Register PHP attribute provider
    const php_attribute_provider = new PhpAttributeSemanticTokensProvider();

    context.subscriptions.push(
        vscode.languages.registerDocumentSemanticTokensProvider(
            [{ language: 'php' }],
            php_attribute_provider,
            new vscode.SemanticTokensLegend(['conventionMethod'])
        )
    );

    console.log('PHP attribute provider registered for PHP files');

    // Clear status bar on document save
    context.subscriptions.push(
        vscode.workspace.onDidSaveTextDocument(() => {
            definition_provider.clear_status_bar();
        })
    );

    // Register commands
    context.subscriptions.push(
        vscode.commands.registerCommand('rspade.formatPhpFile', async () => {
            const editor = vscode.window.activeTextEditor;
            if (editor && editor.document.languageId === 'php') {
                await vscode.commands.executeCommand('editor.action.formatDocument');
            }
        })
    );

    context.subscriptions.push(
        vscode.commands.registerCommand('rspade.updateNamespace', async () => {
            const editor = vscode.window.activeTextEditor;
            if (editor && editor.document.languageId === 'php') {
                await formatting_provider.update_namespace_only(editor.document);
            }
        })
    );

    // Override built-in copyRelativePath commands to use project root
    const copy_relative_path_handler = async (uri?: vscode.Uri) => {
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
    context.subscriptions.push(
        vscode.commands.registerCommand('rspade.copyRelativePathFromRoot', copy_relative_path_handler)
    );

    // Override built-in commands
    context.subscriptions.push(
        vscode.commands.registerCommand('copyRelativePath', copy_relative_path_handler)
    );

    context.subscriptions.push(
        vscode.commands.registerCommand('copyRelativeFilePath', copy_relative_path_handler)
    );

    // Watch for configuration changes
    context.subscriptions.push(
        vscode.workspace.onDidChangeConfiguration(e => {
            if (e.affectsConfiguration('rspade')) {
                vscode.window.showInformationMessage('RSpade configuration changed. Restart VS Code for some changes to take effect.');
            }
        })
    );

    // Watch for extension update marker file
    watch_for_self_update(context);

    // Watch for terminal close marker file
    watch_for_terminal_close(context);
}

function watch_for_self_update(context: vscode.ExtensionContext) {
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
                } catch (error) {
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

function watch_for_terminal_close(context: vscode.ExtensionContext) {
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
                } catch (error) {
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

export function deactivate() {
    // Cleanup
    if (file_watcher) {
        file_watcher.dispose();
    }
    if (auto_rename_provider) {
        auto_rename_provider.dispose();
    }
}