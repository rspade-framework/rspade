"use strict";
/**
 * RSpade Definition Provider - "Go to Definition" for RSX Classes, Routes, and Components
 *
 * RESOLUTION TYPE PRIORITY MATRIX
 * ================================
 *
 * This provider determines what to navigate to when you click "Go to Definition" on various
 * identifiers across different file types. The resolution logic uses CSV type lists sent to
 * the server endpoint `/_ide/service/resolve_class?type=X,Y,Z` which tries each type in order.
 *
 * FILE TYPE HANDLERS & RESOLUTION RULES:
 *
 * 1. ROUTE PATTERNS (all files)
 *    Pattern: Rsx::Route('Controller') or Rsx.Route('Controller', 'method')
 *    Type: 'php_class'
 *    Reason: Routes always point to PHP controllers (server-side)
 *
 * 2. HREF PATTERNS (Blade, jqhtml)
 *    Pattern: href="/"
 *    Type: 'php_class'
 *    Reason: Resolves URL to controller, always PHP
 *
 * 3. JQHTML EXTENDS ATTRIBUTE (jqhtml only)
 *    Pattern: <Define:My_Component extends="DataGrid_Abstract">
 *    Type: 'jqhtml_class,js_class'
 *    Reason: Component inheritance - try jqhtml component first, then JS class
 *
 * 4. JQHTML $xxx ATTRIBUTES (jqhtml only)
 *    Pattern: $data_source=Frontend_Controller.fetch_data
 *    Type: 'js_class,php_class'
 *    Reason: Try JS class first (for components), then PHP (for controllers/models)
 *
 *    Pattern: $handler=this.on_click
 *    Type: 'jqhtml_class_method'
 *    Special: Resolves to current component's method
 *
 * 5. THIS REFERENCES (jqhtml only)
 *    Pattern: <%= this.data.users %>
 *    Type: 'jqhtml_class_method'
 *    Reason: Always current component's method/property
 *
 * 6. JAVASCRIPT CLASS REFERENCES (JS, jqhtml)
 *    Pattern: class My_Component extends DataGrid_Abstract
 *    Pattern: User_Controller.fetch_all()
 *    Type: 'js_class,php_class'
 *    Reason: Try JS first (component inheritance), then PHP (controllers/models)
 *    Note: JS stub files (auto-generated from PHP) are client-side only, not in manifest
 *
 * 7. PHP CLASS REFERENCES (PHP, Blade)
 *    Pattern: class Contacts_DataGrid extends DataGrid_Abstract
 *    Pattern: use Rsx\Lib\DataGrid_QueryBuilder;
 *    Type: 'php_class'
 *    Reason: In PHP files, class references are always PHP (not JavaScript)
 *
 * 8. BUNDLE ALIASES (PHP only)
 *    Pattern: 'include' => ['jqhtml', 'frontend']
 *    Type: 'bundle_alias'
 *    Reason: Resolves to bundle class definition
 *
 * 9. VIEW REFERENCES (PHP, Blade)
 *    Pattern: @rsx_extends('frontend.layout')
 *    Pattern: rsx_view('frontend.dashboard')
 *    Type: 'view'
 *    Reason: Resolves to Blade view template files
 *
 * METHOD RESOLUTION:
 * When a pattern includes a method (e.g., Controller.method), the server attempts to find
 * the specific method in the class. If the method isn't found but the class is, it returns
 * the class location as a fallback.
 *
 * IMPORTANT: The server endpoint supports CSV type lists for priority ordering.
 * Example: type='php_class,js_class' tries PHP first, then JavaScript.
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
exports.RspadeDefinitionProvider = void 0;
const vscode = __importStar(require("vscode"));
const path = __importStar(require("path"));
const fs = __importStar(require("fs"));
const ide_bridge_client_1 = require("./ide_bridge_client");
class RspadeDefinitionProvider {
    constructor(jqhtml_api) {
        // Create output channel and IDE bridge client
        const output_channel = vscode.window.createOutputChannel('RSpade Framework');
        this.ide_bridge = new ide_bridge_client_1.IdeBridgeClient(output_channel);
        this.jqhtml_api = jqhtml_api;
    }
    /**
     * Find the RSpade project root folder (contains system/app/RSpade/)
     * Works in both single-folder and multi-root workspace modes
     */
    find_rspade_root() {
        if (!vscode.workspace.workspaceFolders) {
            return undefined;
        }
        // Check each workspace folder for system/app/RSpade/
        for (const folder of vscode.workspace.workspaceFolders) {
            const app_rspade = path.join(folder.uri.fsPath, 'system', 'app', 'RSpade');
            if (fs.existsSync(app_rspade)) {
                return folder.uri.fsPath;
            }
        }
        return undefined;
    }
    show_error_status(message) {
        // Create status bar item if it doesn't exist
        if (!this.status_bar_item) {
            this.status_bar_item = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Left, 0);
            this.status_bar_item.command = 'workbench.action.output.toggleOutput';
            this.status_bar_item.tooltip = 'Click to view RSpade output';
        }
        // Set error message with icon
        this.status_bar_item.text = `$(error) RSpade: ${message}`;
        this.status_bar_item.backgroundColor = new vscode.ThemeColor('statusBarItem.errorBackground');
        this.status_bar_item.show();
        // Auto-hide after 5 seconds
        setTimeout(() => {
            this.clear_status_bar();
        }, 5000);
    }
    clear_status_bar() {
        if (this.status_bar_item) {
            this.status_bar_item.hide();
        }
    }
    async provideDefinition(document, position, token) {
        const languageId = document.languageId;
        const fileName = document.fileName;
        // Check for Route() pattern first - works in all file types
        const routeResult = await this.handleRoutePattern(document, position);
        if (routeResult) {
            return routeResult;
        }
        // Check for config() pattern - works in PHP and Blade files
        if (['php', 'blade', 'html'].includes(languageId) ||
            fileName.endsWith('.php') ||
            fileName.endsWith('.blade.php')) {
            const configResult = await this.handleConfigPattern(document, position);
            if (configResult) {
                return configResult;
            }
        }
        // Check for href="/" pattern in Blade/Jqhtml files
        if (fileName.endsWith('.blade.php') || fileName.endsWith('.jqhtml')) {
            const hrefResult = await this.handleHrefPattern(document, position);
            if (hrefResult) {
                return hrefResult;
            }
        }
        // Handle jqhtml-specific patterns
        if (fileName.endsWith('.jqhtml')) {
            // Check for extends="ClassName" attribute
            const extendsResult = await this.handleJqhtmlExtends(document, position);
            if (extendsResult) {
                return extendsResult;
            }
            // Check for $xxx=... attributes (must come before handleThisReference)
            const attrResult = await this.handleJqhtmlAttribute(document, position);
            if (attrResult) {
                return attrResult;
            }
            // Handle "this.xxx" references in template expressions
            const thisResult = await this.handleThisReference(document, position);
            if (thisResult) {
                return thisResult;
            }
        }
        // Handle jqhtml component tags in .blade.php and .jqhtml files
        // TEMPORARILY DISABLED FOR .jqhtml FILES: jqhtml extension now provides this feature
        // Re-enable by uncommenting: || fileName.endsWith('.jqhtml')
        if (fileName.endsWith('.blade.php') /* || fileName.endsWith('.jqhtml') */) {
            const componentResult = await this.handleJqhtmlComponent(document, position);
            if (componentResult) {
                return componentResult;
            }
        }
        // Handle JavaScript/TypeScript files and .js/.jqhtml files
        if (['javascript', 'typescript'].includes(languageId) ||
            fileName.endsWith('.js') ||
            fileName.endsWith('.jqhtml')) {
            const result = await this.handleJavaScriptDefinition(document, position);
            if (result) {
                return result;
            }
        }
        // Handle PHP and Blade files (RSX view references and class references)
        if (['php', 'blade', 'html'].includes(languageId) ||
            fileName.endsWith('.php') ||
            fileName.endsWith('.blade.php')) {
            const result = await this.handlePhpBladeDefinition(document, position);
            if (result) {
                return result;
            }
        }
        // As a fallback, check if the cursor is in a string that is a valid file path
        return this.handleFilePathInString(document, position);
    }
    /**
     * Handle Route() pattern for both PHP and JavaScript
     * Detects patterns like:
     * - Rsx::Route('Controller')              (defaults to 'index')
     * - Rsx::Route('Controller::method')      (explicit method via ::)
     * - Rsx::Route('Spa_Action_Name')         (SPA action)
     * - Rsx.Route('Controller')               (JavaScript)
     * - Rsx.Route('Controller::method')       (JavaScript)
     *
     * Resolution: Routes can point to PHP controllers OR SPA actions (JavaScript)
     * Type: 'php_class,js_class' (try PHP first for controllers, then JS for SPA actions)
     */
    async handleRoutePattern(document, position) {
        const line = document.lineAt(position.line).text;
        // Match Route() with single string parameter
        // Matches: Rsx::Route('Something') or Rsx.Route("Something") or Rsx::Route('Class::method')
        const routePattern = /(?:Rsx::Route|Rsx\.Route)\s*\(\s*['"]([A-Z][A-Za-z0-9_:]+)['"].*?\)/;
        const match = line.match(routePattern);
        if (match) {
            const [fullMatch, action] = match;
            const matchStart = line.indexOf(fullMatch);
            const matchEnd = matchStart + fullMatch.length;
            // Check if cursor is within the Route() call
            if (position.character >= matchStart && position.character <= matchEnd) {
                // Parse the action string for :: delimiter
                let controller;
                let method;
                if (action.includes('::')) {
                    // Format: "Controller::method" or "Spa_Action::method"
                    [controller, method] = action.split('::', 2);
                }
                else {
                    // Format: "Controller" or "Spa_Action_Name" - defaults to 'index'
                    controller = action;
                    method = 'index';
                }
                // Try to resolve as PHP controller first, then JS SPA action
                try {
                    const result = await this.queryIdeHelper(controller, method, 'php_class,js_class');
                    return this.createLocationFromResult(result);
                }
                catch (error) {
                    // If method lookup fails, try just the class
                    try {
                        const result = await this.queryIdeHelper(controller, undefined, 'php_class,js_class');
                        return this.createLocationFromResult(result);
                    }
                    catch (error2) {
                        console.error('Error querying IDE helper for route:', error);
                    }
                }
            }
        }
        return undefined;
    }
    /**
     * Handle config() pattern in PHP and Blade files
     * Detects patterns like:
     * - config('rsx.default_user.email')
     * - config("app.name")
     *
     * Searches in both system/config/ and rsx/resource/config/
     * (rsx/resource/config/ takes precedence)
     */
    async handleConfigPattern(document, position) {
        const line = document.lineAt(position.line).text;
        // Match config('key.path') or config("key.path")
        const configPattern = /config\s*\(\s*(['"])([a-zA-Z0-9_.]+)\1\s*\)/g;
        let match;
        while ((match = configPattern.exec(line)) !== null) {
            const fullMatch = match[0];
            const configKey = match[2]; // e.g., "rsx.default_user.email"
            const keyStart = match.index + match[0].indexOf(configKey);
            const keyEnd = keyStart + configKey.length;
            // Check if cursor is on the config key
            if (position.character >= keyStart && position.character < keyEnd) {
                // Parse the config key
                const keyParts = configKey.split('.');
                const configFile = keyParts[0]; // e.g., "rsx"
                const nestedPath = keyParts.slice(1); // e.g., ["default_user", "email"]
                // Get the RSpade project root
                const rspade_root = this.find_rspade_root();
                if (!rspade_root) {
                    return undefined;
                }
                // Search for config file (prioritize rsx/resource/config/)
                const rsxConfigPath = path.join(rspade_root, 'rsx', 'resource', 'config', `${configFile}.php`);
                const systemConfigPath = path.join(rspade_root, 'system', 'config', `${configFile}.php`);
                let configFilePath;
                // Check rsx/resource/config first
                if (fs.existsSync(rsxConfigPath)) {
                    configFilePath = rsxConfigPath;
                }
                else if (fs.existsSync(systemConfigPath)) {
                    configFilePath = systemConfigPath;
                }
                if (!configFilePath) {
                    return undefined;
                }
                // Read the config file
                try {
                    const configContent = fs.readFileSync(configFilePath, 'utf8');
                    // Find the line containing the nested key
                    const location = this.findConfigKeyInFile(configContent, nestedPath, configFilePath);
                    if (location) {
                        this.clear_status_bar();
                        return location;
                    }
                    // If we can't find the specific key, just return the file
                    const fileUri = vscode.Uri.file(configFilePath);
                    const filePosition = new vscode.Position(0, 0);
                    this.clear_status_bar();
                    return new vscode.Location(fileUri, filePosition);
                }
                catch (error) {
                    console.error('Error reading config file:', error);
                    return undefined;
                }
            }
        }
        return undefined;
    }
    /**
     * Find a nested config key in a PHP config file
     * Returns the location of the key definition if found
     */
    findConfigKeyInFile(content, nestedPath, filePath) {
        if (nestedPath.length === 0) {
            // No nested path, return start of file
            const fileUri = vscode.Uri.file(filePath);
            return new vscode.Location(fileUri, new vscode.Position(0, 0));
        }
        // Split content into lines
        const lines = content.split('\n');
        // Search for the key in the file
        // For nested keys like ["default_user", "email"], we need to find:
        // 1. First, find 'default_user' => [
        // 2. Then find 'email' => value
        // Simple approach: search for the last key in quotes
        const targetKey = nestedPath[nestedPath.length - 1];
        const keyPattern = new RegExp(`['"]${targetKey}['"]\\s*=>`, 'i');
        for (let i = 0; i < lines.length; i++) {
            if (keyPattern.test(lines[i])) {
                const fileUri = vscode.Uri.file(filePath);
                const position = new vscode.Position(i, 0);
                return new vscode.Location(fileUri, position);
            }
        }
        return undefined;
    }
    /**
     * Handle href="/" pattern in Blade/Jqhtml files
     * Detects when cursor is on "/" within href attribute
     * Resolves to the controller action that handles the root URL
     */
    async handleHrefPattern(document, position) {
        const line = document.lineAt(position.line).text;
        // Match href="/" or href='/'
        const hrefPattern = /href\s*=\s*(['"])\/\1/g;
        let match;
        while ((match = hrefPattern.exec(line)) !== null) {
            const matchStart = match.index + match[0].indexOf('/');
            const matchEnd = matchStart + 1; // Just the "/" character
            // Check if cursor is on the "/"
            if (position.character >= matchStart && position.character <= matchEnd) {
                try {
                    // Query IDE bridge to resolve "/" URL to route
                    const result = await this.ide_bridge.queryUrl('/');
                    if (result && result.found && result.controller && result.method) {
                        // Resolved to controller/method - navigate to it (always PHP)
                        const phpResult = await this.queryIdeHelper(result.controller, result.method, 'php_class');
                        return this.createLocationFromResult(phpResult);
                    }
                }
                catch (error) {
                    console.error('Error resolving href="/" to route:', error);
                }
            }
        }
        return undefined;
    }
    /**
     * Handle jqhtml extends="" attribute
     * Detects patterns like:
     * - <Define:My_Component extends="DataGrid_Abstract">
     *
     * Resolution: Try jqhtml component first, then JS class
     * Type: 'jqhtml_class,js_class'
     */
    async handleJqhtmlExtends(document, position) {
        const line = document.lineAt(position.line).text;
        // Match extends="ClassName" or extends='ClassName'
        const extendsPattern = /extends\s*=\s*(['"])([A-Z][A-Za-z0-9_]*)\1/g;
        let match;
        while ((match = extendsPattern.exec(line)) !== null) {
            const className = match[2];
            const classStart = match.index + match[0].indexOf(className);
            const classEnd = classStart + className.length;
            // Check if cursor is on the class name
            if (position.character >= classStart && position.character < classEnd) {
                try {
                    // Try jqhtml component first, then JS class
                    const result = await this.queryIdeHelper(className, undefined, 'jqhtml_class,js_class');
                    return this.createLocationFromResult(result);
                }
                catch (error) {
                    console.error('Error resolving jqhtml extends:', error);
                }
            }
        }
        return undefined;
    }
    /**
     * Handle jqhtml $xxx=... attributes
     * Detects patterns like:
     * - $data_source=Frontend_Controller.fetch_data
     * - $on_click=this.handle_click
     *
     * Resolution logic:
     * - If starts with "this.", resolve to current component's jqhtml class methods
     * - Otherwise, resolve like JS class references: 'js_class,php_class'
     */
    async handleJqhtmlAttribute(document, position) {
        const line = document.lineAt(position.line).text;
        // Match $attribute=Value or $attribute=this.method or $attribute=Class.method
        // Pattern: $word=(this.)?(Word)(.word)?
        const attrPattern = /\$[a-z_][a-z0-9_]*\s*=\s*(this\.)?([A-Z][A-Za-z0-9_]*)(?:\.([a-z_][a-z0-9_]*))?/gi;
        let match;
        while ((match = attrPattern.exec(line)) !== null) {
            const hasThis = !!match[1]; // "this." prefix
            const className = match[2];
            const methodName = match[3]; // Optional method after dot
            const classStart = match.index + match[0].indexOf(className);
            const classEnd = classStart + className.length;
            // Check if cursor is on the class name
            if (position.character >= classStart && position.character < classEnd) {
                if (hasThis) {
                    // this.method - resolve to current component's methods
                    // Get the component name from the file
                    let componentName;
                    const fullText = document.getText();
                    const defineMatch = fullText.match(/<Define:([A-Z][A-Za-z0-9_]*)/);
                    if (defineMatch) {
                        componentName = defineMatch[1];
                    }
                    else {
                        // If no Define tag, try to get component name from filename
                        const fileName = document.fileName;
                        const baseName = fileName.split('/').pop()?.replace('.jqhtml', '') || '';
                        if (baseName) {
                            // Convert snake_case to PascalCase with underscores
                            componentName = baseName.split('_').map(part => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase()).join('_');
                        }
                    }
                    if (!componentName) {
                        return undefined;
                    }
                    try {
                        // The className here is actually the method name after "this."
                        // We need to use the component name as the identifier
                        const result = await this.queryIdeHelper(componentName, className.toLowerCase(), 'jqhtml_class_method');
                        return this.createLocationFromResult(result);
                    }
                    catch (error) {
                        console.error('Error resolving jqhtml this reference:', error);
                    }
                }
                else {
                    // Class.method or Class - resolve like JS class references
                    try {
                        const result = await this.queryIdeHelper(className, methodName, 'js_class,php_class');
                        return this.createLocationFromResult(result);
                    }
                    catch (error) {
                        console.error('Error resolving jqhtml attribute class:', error);
                    }
                }
            }
        }
        return undefined;
    }
    /**
     * Handle "this.xxx" references in .jqhtml files
     * Only handles patterns where cursor is on a word after "this."
     * Resolves to JavaScript class method if it exists
     */
    async handleThisReference(document, position) {
        const line = document.lineAt(position.line).text;
        const fileName = document.fileName;
        // Check if cursor is on a word after "this."
        // Get the word at cursor position
        const wordRange = document.getWordRangeAtPosition(position);
        if (!wordRange) {
            return undefined;
        }
        const word = document.getText(wordRange);
        // Check if "this." appears before this word
        const beforeWord = line.substring(0, wordRange.start.character);
        if (!beforeWord.endsWith('this.')) {
            return undefined;
        }
        // Get the component name from the file
        let componentName;
        const fullText = document.getText();
        const defineMatch = fullText.match(/<Define:([A-Z][A-Za-z0-9_]*)/);
        if (defineMatch) {
            componentName = defineMatch[1];
        }
        else {
            // If no Define tag, try to get component name from filename
            // e.g., user_card.jqhtml -> User_Card
            const baseName = path.basename(fileName, '.jqhtml');
            if (baseName) {
                // Convert snake_case to PascalCase with underscores
                componentName = baseName.split('_').map(part => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase()).join('_');
            }
        }
        if (!componentName) {
            return undefined;
        }
        try {
            // Try to find the JavaScript class and method
            // First try jqhtml_class type to find the JS file
            const result = await this.queryIdeHelper(componentName, word, 'jqhtml_class_method');
            if (result && result.found) {
                return this.createLocationFromResult(result);
            }
        }
        catch (error) {
            // Method not found, try just the class
            try {
                const result = await this.queryIdeHelper(componentName, undefined, 'jqhtml_class');
                return this.createLocationFromResult(result);
            }
            catch (error2) {
                console.error('Error querying IDE helper for this reference:', error2);
            }
        }
        return undefined;
    }
    /**
     * Handle jqhtml component tags in .blade.php and .jqhtml files
     * Detects uppercase HTML-like tags such as:
     * - <User_Card ... />
     * - <User_Card ...>content</User_Card>
     * - <Foo>content</Foo>
     */
    async handleJqhtmlComponent(document, position) {
        console.log('[JQHTML Component] Entry point - checking component navigation');
        // If JQHTML API not available, skip
        if (!this.jqhtml_api) {
            console.log('[JQHTML Component] JQHTML API not available - skipping');
            return undefined;
        }
        console.log('[JQHTML Component] JQHTML API available');
        // 1. Get the word at cursor position (component name pattern)
        const word_range = document.getWordRangeAtPosition(position, /[A-Z][A-Za-z0-9_]*/);
        if (!word_range) {
            console.log('[JQHTML Component] No word range found at cursor position');
            return undefined;
        }
        const component_name = document.getText(word_range);
        console.log('[JQHTML Component] Found word at cursor:', component_name);
        // 2. Verify it's a component reference (starts with uppercase)
        if (!/^[A-Z]/.test(component_name)) {
            console.log('[JQHTML Component] Word does not start with uppercase - not a component');
            return undefined;
        }
        console.log('[JQHTML Component] Word starts with uppercase - valid component name pattern');
        // 3. Check if cursor is in a tag context
        const line = document.lineAt(position.line).text;
        const before_word = line.substring(0, word_range.start.character);
        console.log('[JQHTML Component] Line text:', line);
        console.log('[JQHTML Component] Text before word:', before_word);
        // Check for opening tags: <ComponentName or closing tags: </ComponentName
        const is_in_tag_context = before_word.match(/<\s*$/) !== null ||
            before_word.match(/<\/\s*$/) !== null;
        console.log('[JQHTML Component] Is in tag context:', is_in_tag_context);
        if (!is_in_tag_context) {
            console.log('[JQHTML Component] Not in tag context - skipping');
            return undefined;
        }
        // 4. Look up component using JQHTML API
        console.log('[JQHTML Component] Calling JQHTML API findComponent for:', component_name);
        const component_def = this.jqhtml_api.findComponent(component_name);
        console.log('[JQHTML Component] JQHTML API result:', component_def);
        if (!component_def) {
            console.log('[JQHTML Component] Component not found in JQHTML index');
            return undefined;
        }
        // 5. Return the location
        console.log('[JQHTML Component] Returning location:', component_def.uri.fsPath, 'at position', component_def.position);
        return new vscode.Location(component_def.uri, component_def.position);
    }
    /**
     * Handle JavaScript class references in .js and .jqhtml files
     * Detects patterns like:
     * - class My_Component extends DataGrid_Abstract
     * - User_Controller.fetch_all()
     * - await Product_Model.fetch(123)
     *
     * Resolution: Try JS classes first (for component inheritance), then PHP classes (for controllers/models)
     * Type: 'js_class,php_class'
     *
     * Note: JS stub files (auto-generated from PHP) are client-side only and not in the manifest,
     * so there's no conflict - the server will correctly return PHP classes when they exist.
     */
    async handleJavaScriptDefinition(document, position) {
        const line = document.lineAt(position.line).text;
        // Try to match a class name first (uppercase with underscores)
        let wordRange = document.getWordRangeAtPosition(position, /[A-Z][A-Za-z0-9_]*/);
        if (wordRange) {
            const word = document.getText(wordRange);
            // Check if this looks like an RSX class name (contains underscore and starts with capital)
            if (word.includes('_') && /^[A-Z]/.test(word)) {
                // Check if we're on a method call (look for a dot and method name after the class)
                let method_name;
                const wordEnd = wordRange.end.character;
                // Look for pattern like "ClassName.methodName"
                const methodMatch = line.substring(wordEnd).match(/^\.([a-z_][a-z0-9_]*)/i);
                if (methodMatch) {
                    method_name = methodMatch[1];
                }
                // Query the IDE helper endpoint
                // Try JS classes first (component inheritance), then PHP (controllers/models)
                try {
                    const result = await this.queryIdeHelper(word, method_name, 'js_class,php_class');
                    return this.createLocationFromResult(result);
                }
                catch (error) {
                    console.error('Error querying IDE helper:', error);
                }
                return undefined;
            }
        }
        // Try to match a method name (lowercase with underscores)
        wordRange = document.getWordRangeAtPosition(position, /[a-z_][a-z0-9_]*/);
        if (wordRange) {
            const method_name = document.getText(wordRange);
            const wordStart = wordRange.start.character;
            // Look backwards for "ClassName." or "ClassName::" pattern before the method
            const beforeMethod = line.substring(0, wordStart);
            const classMatch = beforeMethod.match(/([A-Z][A-Za-z0-9_]*)(?:\.|::)$/);
            if (classMatch) {
                const class_name = classMatch[1];
                // Check if the class name looks like an RSX class (contains underscore)
                if (class_name.includes('_')) {
                    // Query the IDE helper for this class and method
                    try {
                        const result = await this.queryIdeHelper(class_name, method_name, 'js_class,php_class');
                        return this.createLocationFromResult(result);
                    }
                    catch (error) {
                        console.error('Error querying IDE helper for method:', error);
                    }
                }
            }
        }
        return undefined;
    }
    async handlePhpBladeDefinition(document, position) {
        const line = document.lineAt(position.line).text;
        const charPosition = position.character;
        // Pattern 1: @rsx_extends('View_Name') or @rsx_include('View_Name')
        // Pattern 2: rsx_view('View_Name')
        // Pattern 3: Class references like Demo_Controller
        // Check if we're inside a string literal
        let inString = false;
        let stringStart = -1;
        let stringEnd = -1;
        let quoteChar = '';
        // Find string boundaries around cursor position
        for (let i = 0; i < line.length; i++) {
            const char = line[i];
            if ((char === '"' || char === "'") && (i === 0 || line[i - 1] !== '\\')) {
                if (!inString) {
                    inString = true;
                    stringStart = i;
                    quoteChar = char;
                }
                else if (char === quoteChar) {
                    stringEnd = i;
                    if (charPosition > stringStart && charPosition <= stringEnd) {
                        // Cursor is inside this string
                        break;
                    }
                    inString = false;
                    stringStart = -1;
                    stringEnd = -1;
                }
            }
        }
        // If we're inside a string, extract the identifier
        if (stringStart >= 0) {
            const stringContent = line.substring(stringStart + 1, stringEnd >= 0 ? stringEnd : line.length);
            // Check what context this string is in
            const beforeString = line.substring(0, stringStart);
            // Check if we're in a bundle 'include' array
            // Look for patterns like 'include' => [ or "include" => [
            // We need to check previous lines too for context
            let inBundleInclude = false;
            // Check current line for include array
            if (/['"]include['"]\s*=>\s*\[/.test(line)) {
                inBundleInclude = true;
            }
            else {
                // Check previous lines for context (up to 10 lines back)
                for (let i = Math.max(0, position.line - 10); i < position.line; i++) {
                    const prevLine = document.lineAt(i).text;
                    if (/['"]include['"]\s*=>\s*\[/.test(prevLine)) {
                        // Check if we haven't closed the array yet
                        let openBrackets = 0;
                        for (let j = i; j <= position.line; j++) {
                            const checkLine = document.lineAt(j).text;
                            openBrackets += (checkLine.match(/\[/g) || []).length;
                            openBrackets -= (checkLine.match(/\]/g) || []).length;
                        }
                        if (openBrackets > 0) {
                            inBundleInclude = true;
                            break;
                        }
                    }
                }
            }
            // If we're in a bundle include array and the string looks like a bundle alias
            if (inBundleInclude && /^[a-z0-9]+$/.test(stringContent)) {
                try {
                    const result = await this.queryIdeHelper(stringContent, undefined, 'bundle_alias');
                    if (result && result.found) {
                        return this.createLocationFromResult(result);
                    }
                }
                catch (error) {
                    console.error('Error querying IDE helper for bundle alias:', error);
                }
            }
            // Check for RSX blade directives or function calls
            const rsxPatterns = [
                /@rsx_extends\s*\(\s*$/,
                /@rsx_include\s*\(\s*$/,
                /@rsx_layout\s*\(\s*$/,
                /@rsx_component\s*\(\s*$/,
                /rsx_view\s*\(\s*$/,
                /rsx_include\s*\(\s*$/
            ];
            let isRsxView = false;
            for (const pattern of rsxPatterns) {
                if (pattern.test(beforeString)) {
                    isRsxView = true;
                    break;
                }
            }
            if (isRsxView && stringContent) {
                // Query as a view
                const result = await this.queryIdeHelper(stringContent, undefined, 'view');
                return this.createLocationFromResult(result);
            }
        }
        // If not in a string, check for class references (like in PHP files)
        // But skip this if we're inside a Route() call
        const routePattern = /(?:Rsx::Route|Rsx\.Route)\s*\([^)]*\)/g;
        let isInRoute = false;
        let routeMatch;
        while ((routeMatch = routePattern.exec(line)) !== null) {
            const matchStart = routeMatch.index;
            const matchEnd = matchStart + routeMatch[0].length;
            if (position.character >= matchStart && position.character <= matchEnd) {
                isInRoute = true;
                break;
            }
        }
        if (!isInRoute) {
            const wordRange = document.getWordRangeAtPosition(position, /[A-Z][A-Za-z0-9_]*/);
            if (wordRange) {
                const word = document.getText(wordRange);
                // Check if this looks like an RSX class name
                if (word.includes('_') && /^[A-Z]/.test(word)) {
                    try {
                        // When resolving from PHP files, only look for PHP classes
                        // This prevents jumping to JavaScript files when clicking on PHP class references
                        const result = await this.queryIdeHelper(word, undefined, 'php_class');
                        return this.createLocationFromResult(result);
                    }
                    catch (error) {
                        console.error('Error querying IDE helper for class:', error);
                    }
                }
            }
        }
        return undefined;
    }
    createLocationFromResult(result) {
        if (result && result.found) {
            // Get the RSpade project root
            const rspade_root = this.find_rspade_root();
            if (!rspade_root) {
                return undefined;
            }
            // Construct the full file path
            const filePath = path.join(rspade_root, result.file);
            const fileUri = vscode.Uri.file(filePath);
            // Create a position for the definition
            const position = new vscode.Position(result.line - 1, 0); // VS Code uses 0-based line numbers
            // Clear any error status on successful navigation
            this.clear_status_bar();
            return new vscode.Location(fileUri, position);
        }
        return undefined;
    }
    /**
     * Handle file paths in strings - allows "Go to Definition" on file path strings
     * This is a fallback handler that only runs if other definitions aren't found
     */
    async handleFilePathInString(document, position) {
        const line = document.lineAt(position.line).text;
        const charPosition = position.character;
        // Check if we're inside a string literal
        let inString = false;
        let stringStart = -1;
        let stringEnd = -1;
        let quoteChar = '';
        // Find string boundaries around cursor position
        for (let i = 0; i < line.length; i++) {
            const char = line[i];
            if ((char === '"' || char === "'") && (i === 0 || line[i - 1] !== '\\')) {
                if (!inString) {
                    inString = true;
                    stringStart = i;
                    quoteChar = char;
                }
                else if (char === quoteChar) {
                    stringEnd = i;
                    if (charPosition > stringStart && charPosition <= stringEnd) {
                        // Cursor is inside this string
                        break;
                    }
                    inString = false;
                    stringStart = -1;
                    stringEnd = -1;
                }
            }
        }
        // If we're not inside a string, return undefined
        if (stringStart < 0) {
            return undefined;
        }
        // Extract the string content
        const stringContent = line.substring(stringStart + 1, stringEnd >= 0 ? stringEnd : line.length);
        // Check if the string looks like a file path (contains forward slashes or dots)
        if (!stringContent.includes('/') && !stringContent.includes('.')) {
            return undefined;
        }
        // Get the RSpade project root
        const rspade_root = this.find_rspade_root();
        if (!rspade_root) {
            return undefined;
        }
        // Try to resolve the path relative to the workspace
        const possiblePath = path.join(rspade_root, stringContent);
        // Check if the file exists
        try {
            const stat = fs.statSync(possiblePath);
            if (stat.isFile()) {
                // Create a location for the file
                const fileUri = vscode.Uri.file(possiblePath);
                const position = new vscode.Position(0, 0); // Go to start of file
                return new vscode.Location(fileUri, position);
            }
        }
        catch (error) {
            // File doesn't exist, that's ok - just return undefined
        }
        return undefined;
    }
    async queryIdeHelper(identifier, methodName, type) {
        const params = { identifier };
        if (methodName) {
            params.method = methodName;
        }
        if (type) {
            params.type = type;
        }
        try {
            const result = await this.ide_bridge.request('/_ide/service/resolve_class', params);
            return result;
        }
        catch (error) {
            this.show_error_status('IDE helper request failed');
            throw error;
        }
    }
}
exports.RspadeDefinitionProvider = RspadeDefinitionProvider;
//# sourceMappingURL=definition_provider.js.map