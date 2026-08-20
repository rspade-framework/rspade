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
exports.LaravelCompletionProvider = void 0;
const vscode = __importStar(require("vscode"));
class LaravelCompletionProvider {
    constructor() {
        this.laravel_functions = new Map();
        this.initialize_laravel_functions();
    }
    initialize_laravel_functions() {
        // Path Helpers
        this.add_function('base_path', 'base_path(string $path = \'\'): string', 'Get the base path of the Laravel installation. Optionally append a path.');
        this.add_function('app_path', 'app_path(string $path = \'\'): string', 'Get the path to the app folder. Optionally append a path.');
        this.add_function('config_path', 'config_path(string $path = \'\'): string', 'Get the configuration path. Optionally append a path.');
        this.add_function('database_path', 'database_path(string $path = \'\'): string', 'Get the database path. Optionally append a path.');
        this.add_function('public_path', 'public_path(string $path = \'\'): string', 'Get the public path. Optionally append a path.');
        this.add_function('resource_path', 'resource_path(string $path = \'\'): string', 'Get the path to the resources folder. Optionally append a path.');
        this.add_function('storage_path', 'storage_path(string $path = \'\'): string', 'Get the path to the storage folder. Optionally append a path.');
        // Environment & Config
        this.add_function('env', 'env(string $key, mixed $default = null): mixed', 'Get the value of an environment variable. Supports a default value.');
        this.add_function('config', 'config(string|array|null $key = null, mixed $default = null): mixed', 'Get/set a configuration value. Pass an array to set multiple values.');
        this.add_function('app', 'app(string|null $abstract = null, array $parameters = []): mixed', 'Get the available container instance or resolve a type from the container.');
        // URL & Asset Helpers
        this.add_function('url', 'url(string|null $path = null, mixed $parameters = [], bool|null $secure = null): string', 'Generate a URL for the application.');
        this.add_function('asset', 'asset(string $path, bool|null $secure = null): string', 'Generate an asset URL.');
        this.add_function('secure_asset', 'secure_asset(string $path): string', 'Generate an asset URL using HTTPS.');
        this.add_function('route', 'route(string $name, mixed $parameters = [], bool $absolute = true): string', 'Generate the URL to a named route.');
        this.add_function('mix', 'mix(string $path, string $manifestDirectory = \'\'): string', 'Get the path to a versioned Mix file.');
        // Request & Response
        this.add_function('request', 'request(array|string|null $key = null, mixed $default = null): mixed', 'Get an instance of the current request or an input item from the request.');
        this.add_function('response', 'response(mixed $content = \'\', int $status = 200, array $headers = []): mixed', 'Create a new response instance.');
        this.add_function('redirect', 'redirect(string|null $to = null, int $status = 302, array $headers = [], bool|null $secure = null): mixed', 'Get an instance of the redirector or create a new redirect response.');
        this.add_function('back', 'back(int $status = 302, array $headers = [], mixed $fallback = false): mixed', 'Create a new redirect response to the previous location.');
        this.add_function('abort', 'abort(int $code, string $message = \'\', array $headers = []): never', 'Throw an HttpException with the given data.');
        this.add_function('abort_if', 'abort_if(bool $boolean, int $code, string $message = \'\', array $headers = []): void', 'Throw an HttpException with the given data if the given condition is true.');
        this.add_function('abort_unless', 'abort_unless(bool $boolean, int $code, string $message = \'\', array $headers = []): void', 'Throw an HttpException with the given data unless the given condition is true.');
        // Authentication & Session
        this.add_function('auth', 'auth(string|null $guard = null): mixed', 'Get the available auth instance or a specific guard.');
        this.add_function('session', 'session(array|string|null $key = null, mixed $default = null): mixed', 'Get/set the specified session value.');
        this.add_function('old', 'old(string|null $key = null, mixed $default = null): mixed', 'Retrieve an old input item.');
        this.add_function('cookie', 'cookie(string|null $name = null, string|null $value = null, int $minutes = 0, ...): mixed', 'Create a new cookie instance.');
        this.add_function('csrf_token', 'csrf_token(): string', 'Get the CSRF token value.');
        this.add_function('csrf_field', 'csrf_field(): string', 'Generate a CSRF token form field.');
        // Caching
        this.add_function('cache', 'cache(mixed ...$arguments): mixed', 'Get/set the specified cache value.');
        // Collections & Arrays
        this.add_function('collect', 'collect(mixed $value = null): mixed', 'Create a collection from the given value.');
        this.add_function('data_get', 'data_get(mixed $target, string|array|null $key, mixed $default = null): mixed', 'Get an item from an array or object using "dot" notation.');
        this.add_function('data_set', 'data_set(mixed &$target, string|array $key, mixed $value, bool $overwrite = true): mixed', 'Set an item on an array or object using dot notation.');
        this.add_function('data_forget', 'data_forget(mixed &$target, string|array $key): mixed', 'Remove an item from an array or object using "dot" notation.');
        // Debugging
        this.add_function('dd', 'dd(mixed ...$vars): never', 'Dump the given variables and end the script.');
        this.add_function('dump', 'dump(mixed ...$vars): void', 'Dump the given variables.');
        this.add_function('info', 'info(string $message, array $context = []): void', 'Write some information to the log.');
        this.add_function('logger', 'logger(string|null $message = null, array $context = []): mixed', 'Log a debug message to the logs or get a logger instance.');
        // Events & Jobs
        this.add_function('event', 'event(mixed ...$args): mixed', 'Dispatch an event and call the listeners.');
        this.add_function('dispatch', 'dispatch(mixed $job): mixed', 'Dispatch a job to its appropriate handler.');
        this.add_function('dispatch_now', 'dispatch_now(mixed $job): mixed', 'Dispatch a job immediately (synchronously).');
        this.add_function('dispatch_sync', 'dispatch_sync(mixed $job): mixed', 'Dispatch a job synchronously.');
        // Encryption & Hashing
        this.add_function('bcrypt', 'bcrypt(string $value, array $options = []): string', 'Hash the given value using Bcrypt.');
        this.add_function('encrypt', 'encrypt(mixed $value, bool $serialize = true): string', 'Encrypt the given value.');
        this.add_function('decrypt', 'decrypt(string $payload, bool $unserialize = true): mixed', 'Decrypt the given value.');
        // Date & Time
        this.add_function('now', 'now(mixed $tz = null): mixed', 'Create a new Carbon instance for the current time.');
        this.add_function('today', 'today(mixed $tz = null): mixed', 'Create a new Carbon instance for the current date.');
        // Translation
        this.add_function('trans', 'trans(string|null $key = null, array $replace = [], string|null $locale = null): string', 'Translate the given message.');
        this.add_function('trans_choice', 'trans_choice(string $key, int|array|\\Countable $number, array $replace = [], string|null $locale = null): string', 'Translate the given message with a count.');
        this.add_function('__', '__(string|null $key = null, array $replace = [], string|null $locale = null): string', 'Translate the given message (alias for trans).');
        // Validation
        this.add_function('validator', 'validator(array $data = [], array $rules = [], array $messages = [], array $customAttributes = []): mixed', 'Create a new Validator instance.');
        // Other Laravel Helpers
        this.add_function('class_basename', 'class_basename(string|object $class): string', 'Get the class "basename" of the given object/class.');
        this.add_function('class_uses_recursive', 'class_uses_recursive(string|object $class): array', 'Returns all traits used by a class, its parent classes and trait of their traits.');
        this.add_function('trait_uses_recursive', 'trait_uses_recursive(string|object $trait): array', 'Returns all traits used by a trait and its traits.');
        this.add_function('value', 'value(mixed $value, mixed ...$args): mixed', 'Return the default value of the given value.');
        this.add_function('with', 'with(mixed $value, callable|null $callback = null): mixed', 'Return the given value, optionally passed through the given callback.');
        this.add_function('tap', 'tap(mixed $value, callable|null $callback = null): mixed', 'Call the given Closure with the given value then return the value.');
        this.add_function('blank', 'blank(mixed $value): bool', 'Determine if the given value is "blank".');
        this.add_function('filled', 'filled(mixed $value): bool', 'Determine if a value is "filled".');
        this.add_function('optional', 'optional(mixed $value, callable|null $callback = null): mixed', 'Provide access to optional objects.');
        this.add_function('rescue', 'rescue(callable $callback, mixed $rescue = null, bool|callable $report = true): mixed', 'Catch a potential exception and return a default value.');
        this.add_function('retry', 'retry(int $times, callable $callback, int|\\Closure $sleepMilliseconds = 0, callable|null $when = null): mixed', 'Retry an operation a given number of times.');
        this.add_function('throw_if', 'throw_if(mixed $condition, \\Throwable|string $exception = \'RuntimeException\', mixed ...$parameters): mixed', 'Throw the given exception if the given condition is true.');
        this.add_function('throw_unless', 'throw_unless(mixed $condition, \\Throwable|string $exception = \'RuntimeException\', mixed ...$parameters): mixed', 'Throw the given exception unless the given condition is true.');
        this.add_function('windows_os', 'windows_os(): bool', 'Determine whether the current environment is Windows based.');
    }
    add_function(name, signature, documentation) {
        this.laravel_functions.set(name, {
            name,
            signature,
            documentation
        });
    }
    provideCompletionItems(document, position, token, context) {
        const line_text = document.lineAt(position).text;
        const before_cursor = line_text.substring(0, position.character);
        // Check if we're in a position where a function name might be typed
        const function_pattern = /([a-z_][a-z0-9_]*)?$/i;
        const match = before_cursor.match(function_pattern);
        if (!match) {
            return [];
        }
        const prefix = match[1] || '';
        const completion_items = [];
        this.laravel_functions.forEach((func_data, func_name) => {
            if (func_name.toLowerCase().startsWith(prefix.toLowerCase())) {
                const item = new vscode.CompletionItem(func_name, vscode.CompletionItemKind.Function);
                item.detail = func_data.signature;
                item.documentation = new vscode.MarkdownString(func_data.documentation);
                // Create snippet for function with parentheses
                const params = this.extract_parameters(func_data.signature);
                if (params.length > 0) {
                    // Create snippet with placeholders for required parameters
                    const snippet_params = params
                        .filter(p => !p.optional)
                        .map((p, i) => `\${${i + 1}:${p.name}}`)
                        .join(', ');
                    item.insertText = new vscode.SnippetString(`${func_name}(${snippet_params})`);
                }
                else {
                    item.insertText = new vscode.SnippetString(`${func_name}()`);
                }
                item.sortText = '0' + func_name; // Prioritize Laravel functions
                completion_items.push(item);
            }
        });
        return completion_items;
    }
    extract_parameters(signature) {
        const params = [];
        // Extract everything between parentheses
        const match = signature.match(/\((.*?)\)/);
        if (!match) {
            return params;
        }
        const params_str = match[1];
        if (!params_str.trim()) {
            return params;
        }
        // Split by comma but handle nested parentheses/brackets
        const param_parts = this.smart_split(params_str, ',');
        for (const param of param_parts) {
            const param_match = param.match(/\$([a-z_][a-z0-9_]*)/i);
            if (param_match) {
                const param_name = param_match[1];
                const is_optional = param.includes('=');
                params.push({ name: param_name, optional: is_optional });
            }
        }
        return params;
    }
    smart_split(str, delimiter) {
        const result = [];
        let current = '';
        let depth = 0;
        for (let i = 0; i < str.length; i++) {
            const char = str[i];
            if (char === '(' || char === '[' || char === '{') {
                depth++;
            }
            else if (char === ')' || char === ']' || char === '}') {
                depth--;
            }
            else if (char === delimiter && depth === 0) {
                result.push(current.trim());
                current = '';
                continue;
            }
            current += char;
        }
        if (current.trim()) {
            result.push(current.trim());
        }
        return result;
    }
}
exports.LaravelCompletionProvider = LaravelCompletionProvider;
//# sourceMappingURL=laravel_completion_provider.js.map