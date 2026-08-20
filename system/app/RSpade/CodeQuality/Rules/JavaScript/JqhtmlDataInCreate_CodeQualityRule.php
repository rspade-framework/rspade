<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class JqhtmlDataInCreate_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-JQHTML-01';
    }

    public function get_name(): string
    {
        return 'Jqhtml Component this.data in on_create() Check';
    }

    public function get_description(): string
    {
        return 'Ensures this.data is not used in on_create() method of Jqhtml components';
    }

    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Check for improper this.data usage in on_create() methods of Jqhtml components
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor and node_modules
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }

        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }

        // Get violations from AST parser (with caching)
        $violations = $this->parse_with_acorn($file_path);

        if (empty($violations)) {
            return;
        }

        // Process violations
        foreach ($violations as $violation) {
            $line_number = $violation['line'];
            $class_name = $violation['className'] ?? 'unknown';
            $code_snippet = $violation['codeSnippet'] ?? 'this.data';

            $this->add_violation(
                $file_path,
                $line_number,
                "Jqhtml Component Error: 'this.data' used in on_create() method of class '{$class_name}'. " .
                "The 'this.data' property is only available during on_load() and later lifecycle steps. " .
                "It is used to store data fetched from AJAX or other async operations.",
                $code_snippet,
                "Use 'this.args' instead to access the parameters passed to the component at creation time. " .
                "The args contain attributes from the component's invocation in templates or JavaScript. " .
                "Example: Change 'this.data.initial_value' to 'this.args.initial_value'.",
                'high'
            );
        }
    }

    /**
     * Parse JavaScript file with acorn AST parser
     * Results are cached based on file modification time
     */
    protected function parse_with_acorn(string $file_path): array
    {
        // Create cache directory if needed
        $cache_dir = storage_path('rsx-tmp/persistent/code-quality-jqhtml-data');
        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0777, true);
        }

        // Generate cache key based on file path and mtime
        $file_mtime = filemtime($file_path);
        $file_size = filesize($file_path);
        $cache_key = md5($file_path . ':jqhtml-data');
        $cache_file = $cache_dir . '/' . $cache_key . '.json';

        // Check if cached result exists and is valid
        if (file_exists($cache_file)) {
            $cache_data = json_decode(file_get_contents($cache_file), true);
            if ($cache_data &&
                isset($cache_data['mtime']) && $cache_data['mtime'] == $file_mtime &&
                isset($cache_data['size']) && $cache_data['size'] == $file_size) {
                // Cache is valid
                return $cache_data['violations'] ?? [];
            }
        }

        // Create parser script if it doesn't exist
        $parser_script = storage_path('rsx-tmp/persistent/parse-jqhtml-data.js');
        if (!file_exists($parser_script)) {
            $this->create_parser_script($parser_script);
        }

        // Run parser. NODE_PATH pins module resolution at the framework's node_modules: the
        // script lives under storage/rsx-tmp, which is at the PROJECT ROOT since storage was
        // relocated out of system/, so node's upward walk never reaches system/node_modules.
        $command = sprintf(
            'NODE_PATH=%s node %s %s 2>&1',
            escapeshellarg(base_path('node_modules')),
            escapeshellarg($parser_script),
            escapeshellarg($file_path)
        );

        $output = shell_exec('bash -c ' . escapeshellarg($command));

        if (!$output) {
            return [];
        }

        $result = json_decode($output, true);
        if (!$result || !isset($result['violations'])) {
            // Parser error - don't cache
            return [];
        }

        // Cache the result
        $cache_data = [
            'mtime' => $file_mtime,
            'size' => $file_size,
            'violations' => $result['violations']
        ];
        file_put_contents_safe($cache_file, json_encode($cache_data));

        return $result['violations'];
    }

    /**
     * Create the Node.js parser script
     */
    protected function create_parser_script(string $script_path): void
    {
        $dir = dirname($script_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $script_content = <<<'JAVASCRIPT'
#!/usr/bin/env node

const fs = require('fs');
const acorn = require('acorn');
const walk = require('acorn-walk');

// Classes that are Jqhtml components
const JQHTML_COMPONENTS = new Set([
    'Component', '_Base_Jqhtml_Component', 'Component'
]);

function analyzeFile(filePath) {
    const code = fs.readFileSync(filePath, 'utf8');
    const lines = code.split('\n');

    let ast;
    try {
        ast = acorn.parse(code, {
            ecmaVersion: 2020,
            sourceType: 'module',
            locations: true
        });
    } catch (e) {
        // Parse error - return empty violations
        console.log(JSON.stringify({ violations: [] }));
        return;
    }

    const violations = [];
    let currentClass = null;
    let inOnCreate = false;

    // Helper to check if a class extends Component
    function isJqhtmlComponent(extendsClass) {
        if (!extendsClass) return false;
        return JQHTML_COMPONENTS.has(extendsClass) ||
               extendsClass.includes('Component') ||
               extendsClass.includes('Jqhtml');
    }

    // Walk the AST
    walk.simple(ast, {
        ClassDeclaration(node) {
            currentClass = {
                name: node.id.name,
                extends: node.superClass?.name,
                isJqhtml: isJqhtmlComponent(node.superClass?.name)
            };
        },

        ClassExpression(node) {
            currentClass = {
                name: node.id?.name || 'anonymous',
                extends: node.superClass?.name,
                isJqhtml: isJqhtmlComponent(node.superClass?.name)
            };
        },

        MethodDefinition(node) {
            // Check if this is on_create method
            if (node.key.name === 'on_create' && currentClass?.isJqhtml) {
                inOnCreate = true;

                // Walk the method body looking for this.data
                walk.simple(node.value.body, {
                    MemberExpression(memberNode) {
                        // Check for this.data pattern
                        if (memberNode.object.type === 'ThisExpression' &&
                            memberNode.property.name === 'data') {
                            // Found this.data in on_create
                            const lineContent = lines[memberNode.loc.start.line - 1] || '';
                            violations.push({
                                line: memberNode.loc.start.line,
                                column: memberNode.loc.start.column,
                                className: currentClass.name,
                                codeSnippet: lineContent.trim()
                            });
                        }
                    }
                });

                inOnCreate = false;
            }
        }
    });

    console.log(JSON.stringify({ violations }));
}

// Main
if (process.argv.length < 3) {
    console.error('Usage: node parse-jqhtml-data.js <file-path>');
    process.exit(1);
}

try {
    analyzeFile(process.argv[2]);
} catch (e) {
    console.error('Error:', e.message);
    console.log(JSON.stringify({ violations: [] }));
}
JAVASCRIPT;

        file_put_contents_safe($script_path, $script_content);
        chmod($script_path, 0755);
    }
}