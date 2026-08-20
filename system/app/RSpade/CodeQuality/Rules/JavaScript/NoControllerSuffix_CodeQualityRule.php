<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class NoControllerSuffix_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-CONTROLLER-01';
    }
    
    public function get_name(): string
    {
        return 'No Controller Suffix in JavaScript';
    }
    
    public function get_description(): string
    {
        return 'JavaScript classes cannot use Controller suffix - reserved for PHP controllers';
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
     * Check that JavaScript classes don't use Controller suffix
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor and node_modules directories
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }
        
        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }
        
        // Check if metadata has a class name (from Manifest)
        if (!isset($metadata['class'])) {
            return;
        }
        
        $class_name = $metadata['class'];
        
        // Check if class name ends with Controller or _controller (case sensitive)
        if (str_ends_with($class_name, 'Controller') || str_ends_with($class_name, '_controller')) {
            // Find the line where the class is declared
            $lines = explode("\n", $contents);
            $line_number = 0;
            $code_snippet = '';
            
            foreach ($lines as $i => $line) {
                // Look for ES6 class declaration
                if (preg_match('/\bclass\s+' . preg_quote($class_name) . '\b/', $line)) {
                    $line_number = $i + 1;
                    $code_snippet = $this->get_code_snippet($lines, $i);
                    break;
                }
            }
            
            $resolution = "JavaScript class '{$class_name}' uses the reserved 'Controller' suffix.\n\n";
            $resolution .= "WHY THIS IS PROHIBITED:\n";
            $resolution .= "The 'Controller' suffix is reserved exclusively for PHP controller classes because:\n";
            $resolution .= "1. It maintains clear separation between frontend and backend code\n";
            $resolution .= "2. It prevents confusion when making Ajax_Endpoint_Controller calls from JavaScript\n";
            $resolution .= "3. It ensures consistent naming conventions across the codebase\n";
            $resolution .= "4. Controllers handle HTTP requests and must be server-side PHP classes\n\n";
            
            $resolution .= "HOW TO FIX:\n";
            $resolution .= "Rename the JavaScript class to use a different suffix that describes its purpose:\n";
            
            // Generate suggestions based on common patterns
            $base_name = preg_replace('/(Controller|_controller)$/', '', $class_name);
            $suggestions = [
                $base_name . 'Manager' => 'For managing state or coordinating components',
                $base_name . 'Handler' => 'For handling events or user interactions',
                $base_name . 'Component' => 'For UI components',
                $base_name . 'Service' => 'For service/API interaction logic',
                $base_name . 'View' => 'For view-related logic',
                $base_name . 'Widget' => 'For reusable UI widgets',
                $base_name . 'Helper' => 'For utility/helper functions',
            ];
            
            $resolution .= "\nSUGGESTED ALTERNATIVES:\n";
            foreach ($suggestions as $suggestion => $description) {
                $resolution .= "- {$suggestion}: {$description}\n";
            }
            
            $resolution .= "\nREMEMBER:\n";
            $resolution .= "- JavaScript handles frontend logic and UI interactions\n";
            $resolution .= "- PHP Controllers handle HTTP requests and backend logic\n";
            $resolution .= "- When JavaScript needs to call a controller, use Ajax.internal() or fetch() to the controller's API endpoint\n\n";
            
            $resolution .= "If this naming is absolutely required (extremely rare), add '@JS-CONTROLLER-01-EXCEPTION' comment.";
            
            $this->add_violation(
                $file_path,
                $line_number,
                "JavaScript class '{$class_name}' uses reserved 'Controller' suffix",
                $code_snippet,
                $resolution,
                'high'
            );
        }
    }
}