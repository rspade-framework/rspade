<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class RspadeInternalMethod_CodeQualityRule extends CodeQualityRule_Abstract
{
    protected static $parser = null;
    
    public function get_id(): string
    {
        return 'PHP-INTERNAL-01';
    }
    
    public function get_name(): string
    {
        return 'RSpade Internal Method Usage Check';
    }
    
    public function get_description(): string
    {
        return 'Prohibits calling internal RSpade methods (starting with _) from application code';
    }
    
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }
    
    public function get_default_severity(): string
    {
        return 'high';
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
        // Only check files in ./rsx/ directory
        if (!str_contains($file_path, '/rsx/') && !str_starts_with($file_path, 'rsx/')) {
            return;
        }
        
        // Skip vendor directories
        if (str_contains($file_path, '/vendor/')) {
            return;
        }
        
        try {
            $ast = $this->get_parser()->parse($contents);
            if (!$ast) {
                return;
            }
            
            // First, collect all use statements to resolve short class names
            $use_statements = $this->collect_use_statements($ast);
            
            // Now find all static method calls
            $nodeFinder = new NodeFinder;
            
            // Find static method calls (Class::_method())
            $static_calls = $nodeFinder->findInstanceOf($ast, Node\Expr\StaticCall::class);
            foreach ($static_calls as $call) {
                $this->check_static_call($call, $use_statements, $file_path);
            }
            
            // Find method calls that might be static ($var::_method())
            $method_calls = $nodeFinder->findInstanceOf($ast, Node\Expr\MethodCall::class);
            foreach ($method_calls as $call) {
                $this->check_method_call($call, $use_statements, $file_path);
            }
            
        } catch (Error $error) {
            // Parse error - skip this file
            return;
        }
    }
    
    /**
     * Collect all use statements from the AST
     */
    protected function collect_use_statements($ast): array
    {
        $use_statements = [];
        $nodeFinder = new NodeFinder;
        
        $uses = $nodeFinder->findInstanceOf($ast, Node\Stmt\Use_::class);
        foreach ($uses as $use) {
            foreach ($use->uses as $use_item) {
                $full_name = $use_item->name->toString();
                $alias = $use_item->alias ? $use_item->alias->name : null;
                $short_name = $alias ?: $use_item->name->getLast();
                
                $use_statements[$short_name] = $full_name;
            }
        }
        
        return $use_statements;
    }
    
    /**
     * Check a static method call
     */
    protected function check_static_call(Node\Expr\StaticCall $call, array $use_statements, string $file_path): void
    {
        // Get the method name
        if (!($call->name instanceof Node\Identifier)) {
            return;
        }
        
        $method_name = $call->name->name;
        
        // Check if method starts with underscore
        if (!str_starts_with($method_name, '_')) {
            return;
        }
        
        // Get the class name
        $class_name = null;
        
        if ($call->class instanceof Node\Name) {
            $class_name = $call->class->toString();
            
            // Check if it's a short name that needs to be resolved
            if (!str_contains($class_name, '\\')) {
                // Try to resolve from use statements
                if (isset($use_statements[$class_name])) {
                    $class_name = $use_statements[$class_name];
                }
            }
            
            // Remove leading backslash if present
            $class_name = ltrim($class_name, '\\');
        }
        
        // Check if the class is in App\RSpade namespace
        if ($class_name && str_starts_with($class_name, 'App\\RSpade\\')) {
            $this->add_violation(
                $file_path,
                $call->getLine(),
                "Calling internal RSpade method '{$method_name}'. Methods starting with _ are for framework internal use only.",
                "{$class_name}::{$method_name}()",
                "Do not call methods starting with underscore in the App\\RSpade namespace. " .
                "These methods are internal to the framework and may change without notice in updates. " .
                "Use only public API methods (those not starting with underscore).",
                'high'
            );
        }
    }
    
    /**
     * Check a method call (might be on a class stored in a variable)
     */
    protected function check_method_call(Node\Expr\MethodCall $call, array $use_statements, string $file_path): void
    {
        // For now, we'll skip these as they're harder to analyze
        // Would need data flow analysis to determine if the variable contains an App\RSpade class
        // This could be enhanced in the future
    }
}