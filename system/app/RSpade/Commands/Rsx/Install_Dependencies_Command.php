<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class Install_Dependencies_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:install 
                            {--skip-npm : Skip npm dependency installation}
                            {--skip-permissions : Skip file permission fixes}
                            {--force : Force reinstall even if dependencies exist}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install system dependencies required for RSX framework';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('RSX Framework Dependency Installation');
        $this->info('=====================================');
        $this->line('');
        
        // Check system requirements
        if (!$this->check_system_requirements()) {
            return 1;
        }
        
        // Install npm dependencies
        if (!$this->option('skip-npm')) {
            if (!$this->install_npm_dependencies()) {
                return 1;
            }
        }
        
        // Set up directories and permissions
        if (!$this->option('skip-permissions')) {
            $this->setup_directories();
        }
        
        // Create initial configuration
        $this->create_initial_config();
        
        $this->line('');
        $this->info('[OK] RSX dependencies installed successfully!');
        $this->line('');
        $this->line('Next steps:');
        $this->line('  1. Run "php artisan rsx:manifest:build" to build the initial manifest');
        $this->line('  2. Check "app/RSpade/Scripts/Parsers/" directory for JavaScript parser setup');
        $this->line('  3. Review the RSX documentation at docs/rsx_shell_implementation.md');
        
        return 0;
    }
    
    /**
     * Check system requirements
     */
    protected function check_system_requirements()
    {
        $this->info('Checking system requirements...');
        
        $requirements = [
            'node' => 'Node.js',
            'npm' => 'npm'
        ];
        
        $missing = [];
        
        foreach ($requirements as $command => $name) {
            if (command_exists($command)) {
                $version = shell_exec('bash -c ' . escapeshellarg("$command --version 2>&1"));
                $this->line("  [OK] $name: " . trim($version));
            } else {
                $missing[] = $name;
                $this->error("  [ERROR] $name: Not found");
            }
        }
        
        if (!empty($missing)) {
            $this->line('');
            $this->error('Missing required dependencies: ' . implode(', ', $missing));
            $this->line('Please install the missing dependencies and try again.');
            return false;
        }
        
        return true;
    }
    
    /**
     * Install npm dependencies for JavaScript parsing
     */
    protected function install_npm_dependencies()
    {
        $this->line('');
        $this->info('Installing npm dependencies for JavaScript parsing...');
        
        $parser_dir = base_path('app/RSpade/Scripts/Parsers');
        
        // Check if package.json exists
        $package_json_path = $parser_dir . '/package.json';
        if (!File::exists($package_json_path)) {
            $this->create_package_json($package_json_path);
        }
        
        // Check if node_modules exists and force flag not set
        if (File::isDirectory($parser_dir . '/node_modules') && !$this->option('force')) {
            $this->line('  ℹ npm dependencies already installed. Use --force to reinstall.');
            return true;
        }
        
        // Change to parser directory
        $original_dir = getcwd();
        chdir($parser_dir);
        
        try {
            // Install dependencies
            $this->line('');
            $result = shell_exec_pretty('npm install', true, false);
            
            if ($result['exit_code'] !== 0) {
                $this->error('Failed to install npm dependencies');
                return false;
            }
            
            $this->line('');
            $this->info('  [OK] npm dependencies installed successfully');
            
        } finally {
            // Restore original directory
            chdir($original_dir);
        }
        
        return true;
    }
    
    /**
     * Create package.json for parser dependencies
     */
    protected function create_package_json($path)
    {
        $this->line('  Creating package.json for JavaScript parser...');
        
        $package_config = [
            'name' => 'rsx-js-parser',
            'version' => '1.0.0',
            'description' => 'JavaScript parser for RSX manifest system',
            'private' => true,
            'dependencies' => [
                '@babel/parser' => '^7.22.0',
                '@babel/traverse' => '^7.22.0',
                '@babel/types' => '^7.22.0'
            ],
            'devDependencies' => [
                'eslint' => '^8.40.0',
                'prettier' => '^2.8.0'
            ]
        ];
        
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($package_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        $this->line('  [OK] package.json created');
    }
    
    /**
     * Set up RSX directories and permissions
     */
    protected function setup_directories()
    {
        $this->line('');
        $this->info('Setting up RSX directories...');
        
        $directories = [
            'rsx' => 'Main RSX directory',
            'rsx/controllers' => 'Controllers',
            'rsx/api' => 'API endpoints',
            'rsx/models' => 'Data models',
            'rsx/views' => 'View templates',
            'rsx/components' => 'UI components',
            'rsx/services' => 'Business logic',
            'rsx/js' => 'JavaScript files',
            'rsx/styles' => 'SCSS/LESS files',
            'app/RSpade/Scripts/Parsers' => 'Parser scripts',
            'app/RSpade/Scripts/Parsers/node_modules' => 'npm packages',
            'storage/rsx-build' => 'RSX build artifacts',
            'storage/rsx-tmp' => 'RSX temporary files',
            'storage/rsx-locks' => 'RSX lock files'
        ];
        
        foreach ($directories as $dir => $description) {
            // rsx_project_file_path: 'storage/...' entries follow the RELOCATED storage
            // root (project level), everything else stays base_path()-relative.
            $path = rsx_project_file_path($dir);
            if (!File::isDirectory($path)) {
                File::makeDirectory($path, 0755, true);
                $this->line("  [OK] Created: $dir ($description)");
            } else {
                $this->line("  - Exists: $dir");
            }
        }
        
        // Set permissions on storage directories
        $this->line('');
        $this->info('Setting permissions...');
        
        $storage_dir = storage_path('rsx');
        if (File::isDirectory($storage_dir)) {
            $result = shell_exec_pretty("chmod -R 775 $storage_dir", false, false);
            if ($result['exit_code'] === 0) {
                $this->line('  [OK] Storage permissions set');
            }
        }
    }
    
    /**
     * Create initial RSX configuration
     */
    protected function create_initial_config()
    {
        $this->line('');
        $this->info('Creating initial configuration...');
        
        // Create .gitignore for parsers directory
        $gitignore_path = base_path('app/RSpade/Scripts/Parsers/.gitignore');
        if (!File::exists($gitignore_path)) {
            $gitignore_content = "node_modules/\n*.log\n.DS_Store\n";
            File::put($gitignore_path, $gitignore_content);
            $this->line('  [OK] Created parsers .gitignore');
        }
        
        // Create advanced parser script if not exists
        $parser_path = base_path('app/RSpade/Scripts/Parsers/js-parser.js');
        if (!File::exists($parser_path)) {
            $this->create_advanced_parser($parser_path);
        }
        
        // Ensure simple parser is executable
        $simple_parser = base_path('app/RSpade/Scripts/Parsers/js-parser-simple.js');
        if (File::exists($simple_parser)) {
            chmod($simple_parser, 0755);
            $this->line('  [OK] Set parser scripts as executable');
        }
    }
    
    /**
     * Create the advanced JavaScript parser script
     */
    protected function create_advanced_parser($path)
    {
        $script = <<<'JS'
const fs = require('fs');
const parser = require('@babel/parser');
const traverse = require('@babel/traverse').default;
const t = require('@babel/types');

// Get file path from command line
const filePath = process.argv[2];
if (!filePath) {
    console.error('Usage: node js-parser.js <file-path>');
    process.exit(1);
}

// Read file
let content;
try {
    content = fs.readFileSync(filePath, 'utf8');
} catch (error) {
    console.error(`Error reading file: ${error.message}`);
    process.exit(1);
}

// Parse result object
const result = {
    classes: {},
    functions: {},
    exports: {},
    imports: []
};

try {
    // Parse with Babel
    const ast = parser.parse(content, {
        sourceType: 'module',
        plugins: [
            'jsx',
            'typescript',
            'classProperties',
            'classPrivateProperties',
            'classPrivateMethods',
            ['decorators', { decoratorsBeforeExport: true }],
            'dynamicImport',
            'exportDefaultFrom',
            'exportNamespaceFrom',
            'asyncGenerators',
            'objectRestSpread',
            'optionalCatchBinding',
            'optionalChaining',
            'nullishCoalescingOperator',
            'classStaticBlock'
        ]
    });
    
    // Traverse AST
    traverse(ast, {
        // Class declarations
        ClassDeclaration(path) {
            const className = path.node.id.name;
            const classInfo = {
                name: className,
                extends: path.node.superClass ? path.node.superClass.name : null,
                methods: {},
                staticMethods: {},
                properties: {},
                staticProperties: {}
            };
            
            // Extract methods and properties
            path.node.body.body.forEach(member => {
                if (t.isClassMethod(member)) {
                    const methodInfo = {
                        name: member.key.name,
                        params: member.params.map(p => p.name || p.type),
                        async: member.async,
                        generator: member.generator,
                        kind: member.kind
                    };
                    
                    if (member.static) {
                        classInfo.staticMethods[member.key.name] = methodInfo;
                    } else {
                        classInfo.methods[member.key.name] = methodInfo;
                    }
                } else if (t.isClassProperty(member) || t.isClassPrivateProperty(member)) {
                    const propName = t.isIdentifier(member.key) ? member.key.name : 
                                   t.isPrivateName(member.key) ? '#' + member.key.id.name : 
                                   'unknown';
                    
                    const propInfo = {
                        name: propName,
                        static: member.static,
                        value: member.value ? getValueType(member.value) : null
                    };
                    
                    if (member.static) {
                        classInfo.staticProperties[propName] = propInfo;
                    } else {
                        classInfo.properties[propName] = propInfo;
                    }
                }
            });
            
            result.classes[className] = classInfo;
        },
        
        // Function declarations
        FunctionDeclaration(path) {
            if (path.node.id) {
                const funcName = path.node.id.name;
                result.functions[funcName] = {
                    name: funcName,
                    params: path.node.params.map(p => p.name || p.type),
                    async: path.node.async,
                    generator: path.node.generator
                };
            }
        },
        
        // Imports
        ImportDeclaration(path) {
            const importInfo = {
                source: path.node.source.value,
                specifiers: []
            };
            
            path.node.specifiers.forEach(spec => {
                if (t.isImportDefaultSpecifier(spec)) {
                    importInfo.specifiers.push({
                        type: 'default',
                        local: spec.local.name
                    });
                } else if (t.isImportSpecifier(spec)) {
                    importInfo.specifiers.push({
                        type: 'named',
                        imported: spec.imported.name,
                        local: spec.local.name
                    });
                } else if (t.isImportNamespaceSpecifier(spec)) {
                    importInfo.specifiers.push({
                        type: 'namespace',
                        local: spec.local.name
                    });
                }
            });
            
            result.imports.push(importInfo);
        },
        
        // Exports
        ExportNamedDeclaration(path) {
            if (path.node.declaration) {
                if (t.isClassDeclaration(path.node.declaration) && path.node.declaration.id) {
                    result.exports[path.node.declaration.id.name] = 'class';
                } else if (t.isFunctionDeclaration(path.node.declaration) && path.node.declaration.id) {
                    result.exports[path.node.declaration.id.name] = 'function';
                } else if (t.isVariableDeclaration(path.node.declaration)) {
                    path.node.declaration.declarations.forEach(decl => {
                        if (t.isIdentifier(decl.id)) {
                            result.exports[decl.id.name] = 'variable';
                        }
                    });
                }
            }
            
            // Handle export specifiers
            if (path.node.specifiers) {
                path.node.specifiers.forEach(spec => {
                    if (t.isExportSpecifier(spec)) {
                        result.exports[spec.exported.name] = 'named';
                    }
                });
            }
        },
        
        ExportDefaultDeclaration(path) {
            result.exports.default = getExportType(path.node.declaration);
        }
    });
    
} catch (error) {
    console.error(`Parse error: ${error.message}`);
    process.exit(1);
}

// Helper functions
function getValueType(node) {
    if (t.isStringLiteral(node)) return `"${node.value}"`;
    if (t.isNumericLiteral(node)) return node.value;
    if (t.isBooleanLiteral(node)) return node.value;
    if (t.isNullLiteral(node)) return null;
    if (t.isIdentifier(node)) return node.name;
    if (t.isArrayExpression(node)) return 'array';
    if (t.isObjectExpression(node)) return 'object';
    if (t.isFunctionExpression(node) || t.isArrowFunctionExpression(node)) return 'function';
    return node.type;
}

function getExportType(node) {
    if (t.isClassDeclaration(node)) return 'class';
    if (t.isFunctionDeclaration(node)) return 'function';
    if (t.isIdentifier(node)) return 'identifier';
    if (t.isCallExpression(node)) return 'expression';
    return node.type;
}

// Output result as JSON
console.log(JSON.stringify(result, null, 2));
JS;
        
        File::put($path, $script);
        chmod($path, 0755);
        $this->line('  [OK] Created advanced JavaScript parser');
    }
}