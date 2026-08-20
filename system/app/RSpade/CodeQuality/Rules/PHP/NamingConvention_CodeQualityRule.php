<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class NamingConvention_CodeQualityRule extends CodeQualityRule_Abstract
{
    protected static $parser = null;
    
    public function get_id(): string
    {
        return 'PHP-NAMING-01';
    }
    
    public function get_name(): string
    {
        return 'PHP Method Naming Convention';
    }
    
    public function get_description(): string
    {
        return 'Enforces underscore_case naming convention for PHP methods';
    }
    
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }
    
    public function get_default_severity(): string
    {
        return 'medium';
    }
    
    /**
     * Get or create the parser instance
     */
    protected function get_parser()
    {
        if (static::$parser === null) {
            static::$parser = (new ParserFactory)->createForNewestSupportedVersion();
        }
        return static::$parser;
    }
    
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only apply naming convention to files in ./rsx/ directory
        if (!str_contains($file_path, '/rsx/') && !str_starts_with($file_path, 'rsx/')) {
            return;
        }
        
        try {
            $ast = $this->get_parser()->parse($contents);
            
            // Get the class name from the AST
            $class_name = $this->get_class_name($ast);
            
            if ($class_name) {
                // Try to use reflection if the class is loadable
                $this->check_with_reflection($class_name, $file_path);
            } else {
                // Fall back to AST-only checking
                $this->check_methods($ast, $file_path);
            }
        } catch (Error $error) {
            // Parse error - skip this file for naming checks
            return;
        }
    }
    
    /**
     * Get the fully qualified class name from AST
     */
    protected function get_class_name($ast)
    {
        $nodeFinder = new NodeFinder;
        
        // Find namespace
        $namespace_node = $nodeFinder->findFirstInstanceOf($ast, Node\Stmt\Namespace_::class);
        $namespace = '';
        
        if ($namespace_node && $namespace_node->name) {
            $namespace = $namespace_node->name->toString();
        }
        
        // Find class
        $class_node = $nodeFinder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        
        if ($class_node && $class_node->name) {
            $class_name = $class_node->name->toString();
            return $namespace ? $namespace . '\\' . $class_name : $class_name;
        }
        
        return null;
    }
    
    /**
     * Check using reflection to identify overridden methods
     */
    protected function check_with_reflection($class_name, $file_path)
    {
        // Ensure the class is loaded
        if (!class_exists($class_name)) {
            // Try to require the file once
            try {
                require_once $file_path;
            } catch (\Exception $e) {
                // If we can't load it, fall back to AST checking
                $code = file_get_contents($file_path);
                $ast = $this->get_parser()->parse($code);
                $this->check_methods($ast, $file_path);
                return;
            }
        }
        
        if (!class_exists($class_name)) {
            // Still can't load, fall back to AST
            $code = file_get_contents($file_path);
            $ast = $this->get_parser()->parse($code);
            $this->check_methods($ast, $file_path);
            return;
        }
        
        try {
            $reflection = new ReflectionClass($class_name);
            
            // Check methods
            foreach ($reflection->getMethods() as $method) {
                // Skip methods not declared in this class
                if ($method->getDeclaringClass()->getName() !== $class_name) {
                    continue;
                }
                
                $method_name = $method->getName();
                
                // Skip if this method exists in a parent class (it's an override)
                if ($this->is_overridden_method($reflection, $method_name)) {
                    continue;
                }
                
                // Skip magic methods
                if (strpos($method_name, '__') === 0) {
                    continue;
                }
                
                // Skip private/protected methods that start with underscore
                if (strpos($method_name, '_') === 0) {
                    continue;
                }
                
                // Skip Laravel convention methods
                if ($this->is_laravel_method($method_name)) {
                    continue;
                }
                
                // Check if method name follows underscore_case
                if (!$this->is_underscore_case($method_name)) {
                    $this->add_violation(
                        $file_path,
                        $method->getStartLine(),
                        "Method '{$method_name}' should use underscore_case naming convention",
                        null,
                        "Rename to: " . $this->to_underscore_case($method_name),
                        'medium'
                    );
                }
            }
        } catch (\ReflectionException $e) {
            // Fall back to AST checking if reflection fails
            $code = file_get_contents($file_path);
            $ast = $this->get_parser()->parse($code);
            $this->check_methods($ast, $file_path);
        }
    }
    
    /**
     * Check if a method is overriding a parent method
     */
    protected function is_overridden_method(ReflectionClass $class, $method_name)
    {
        $parent = $class->getParentClass();
        
        while ($parent) {
            // Check for the method in parent
            if ($parent->hasMethod($method_name)) {
                // Make sure we're checking the right scope (static vs instance)
                try {
                    $parent_method = $parent->getMethod($method_name);
                    $our_method = $class->getMethod($method_name);
                    
                    // If both are static or both are instance, it's an override
                    if ($parent_method->isStatic() === $our_method->isStatic()) {
                        return true;
                    }
                } catch (\ReflectionException $e) {
                    // If we can't get the method, assume it's an override to be safe
                    return true;
                }
            }
            $parent = $parent->getParentClass();
        }
        
        // Also check interfaces
        foreach ($class->getInterfaces() as $interface) {
            if ($interface->hasMethod($method_name)) {
                return true;
            }
        }
        
        // Also check traits
        foreach ($class->getTraits() as $trait) {
            if ($trait->hasMethod($method_name)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Fallback*: Check methods in the AST for naming violations (when reflection not available)
     */
    protected function check_methods($ast, $file_path)
    {
        $nodeFinder = new NodeFinder;
        
        // Find all class methods
        $methods = $nodeFinder->findInstanceOf($ast, Node\Stmt\ClassMethod::class);
        
        foreach ($methods as $method) {
            $method_name = $method->name->toString();
            
            // Skip magic methods
            if (strpos($method_name, '__') === 0) {
                continue;
            }
            
            // Skip private/protected methods that start with underscore
            if (strpos($method_name, '_') === 0) {
                continue;
            }
            
            // Skip Laravel convention methods
            if ($this->is_laravel_method($method_name)) {
                continue;
            }
            
            // Check if method name follows underscore_case
            if (!$this->is_underscore_case($method_name)) {
                $visibility = $this->get_visibility($method);
                
                $this->add_violation(
                    $file_path,
                    $method->getLine(),
                    "Method '{$method_name}' should use underscore_case naming convention",
                    null,
                    "Rename to: " . $this->to_underscore_case($method_name),
                    'medium'
                );
            }
        }
    }
    
    /**
     * Check if method name follows Laravel conventions that require camelCase
     */
    protected function is_laravel_method($method_name)
    {
        // Eloquent query scopes - must start with 'scope' followed by PascalCase
        if (preg_match('/^scope[A-Z]/', $method_name)) {
            return true;
        }
        
        // Eloquent accessors - get{Property}Attribute
        if (preg_match('/^get[A-Z].*Attribute$/', $method_name)) {
            return true;
        }
        
        // Eloquent mutators - set{Property}Attribute
        if (preg_match('/^set[A-Z].*Attribute$/', $method_name)) {
            return true;
        }
        
        // Common Laravel framework methods that must keep their names
        $laravel_methods = [
            // Eloquent methods
            'firstOrCreate', 'firstOrNew', 'updateOrCreate', 'findOrFail', 'findOrNew',
            'newInstance', 'newQuery', 'newEloquentBuilder', 'newCollection',
            'toArray', 'toJson', 'jsonSerialize',
            
            // Notification methods
            'toMail', 'toDatabase', 'toBroadcast', 'toNexmo', 'toSlack',
            
            // Request methods
            'prepareForValidation', 'passedValidation', 'failedValidation',
            
            // Resource methods
            'toResponse',
            
            // Service Provider methods
            'registerPolicies', 'registerCommands', 'registerEvents',
            'registerFactories', 'registerMiddleware', 'registerObservers',
            'registerBladeDirectives', 'registerRsxExtendsPrecompiler',
            
            // Controller methods
            'callAction', 'missingMethod',
            
            // Job methods
            'backoff', 'retryUntil',
        ];
        
        if (in_array($method_name, $laravel_methods)) {
            return true;
        }
        
        // Methods that start with common Laravel prefixes
        $laravel_prefixes = [
            'with', 'has', 'can', 'is', 'was', 'will', 'should',
            'register', 'boot', 'bind', 'singleton',
        ];
        
        foreach ($laravel_prefixes as $prefix) {
            if (preg_match('/^' . $prefix . '[A-Z]/', $method_name)) {
                // Only if it matches Laravel's typical pattern
                if (in_array($method_name, [
                    'withTrashed', 'withoutTrashed', 'onlyTrashed',
                    'hasMany', 'hasOne', 'belongsTo', 'belongsToMany',
                    'morphTo', 'morphMany', 'morphOne', 'morphToMany',
                    'registerPolicies', 'registerCommands', 'registerEvents',
                    'bootTraits',
                ])) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check if a string follows underscore_case convention
     */
    protected function is_underscore_case($string)
    {
        // Single word methods are fine
        if (!preg_match('/[A-Z]/', $string)) {
            return true;
        }
        
        // Check for camelCase or PascalCase
        if (preg_match('/[a-z][A-Z]/', $string) || preg_match('/^[A-Z]/', $string)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Convert a string to underscore_case
     */
    protected function to_underscore_case($string)
    {
        // Convert camelCase/PascalCase to underscore_case
        $string = preg_replace('/(?<!^)[A-Z]/', '_$0', $string);
        return strtolower($string);
    }
    
    /**
     * Get visibility from AST method node
     */
    protected function get_visibility($method)
    {
        if ($method->isPublic()) return 'public';
        if ($method->isProtected()) return 'protected';
        if ($method->isPrivate()) return 'private';
        return 'public'; // Default
    }
}