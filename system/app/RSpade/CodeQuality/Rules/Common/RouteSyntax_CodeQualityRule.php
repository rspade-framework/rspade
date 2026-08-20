<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException;

/**
 * Check that Route attributes don't use invalid {param} syntax
 * RSX uses :param syntax instead of Laravel's {param} syntax
 */
class RouteSyntax_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'ROUTE-SYNTAX-01';
    }

    public function get_name(): string
    {
        return 'Route Pattern Syntax Check';
    }

    public function get_description(): string
    {
        return 'Ensures route patterns use :param syntax instead of {param}';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    /**
     * Run during manifest build for immediate feedback
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip files without public static methods
        if (!isset($metadata['public_static_methods']) || !is_array($metadata['public_static_methods'])) {
            return;
        }

        foreach ($metadata['public_static_methods'] as $method_name => $method_data) {
            if (!isset($method_data['attributes']) || !is_array($method_data['attributes'])) {
                continue;
            }

            foreach ($method_data['attributes'] as $attr_name => $attr_instances) {
                // Check for Route attribute
                if ($attr_name !== 'Route' && !str_ends_with($attr_name, '\\Route')) {
                    continue;
                }

                foreach ($attr_instances as $attr_args) {
                    // Check first argument (the route pattern)
                    if (!isset($attr_args[0])) {
                        continue;
                    }

                    $pattern = $attr_args[0];

                    // Check if pattern contains { or }
                    if (strpos($pattern, '{') !== false || strpos($pattern, '}') !== false) {
                        $this->throw_invalid_route_syntax($file_path, $method_name, $pattern);
                    }
                }
            }
        }
    }

    private function throw_invalid_route_syntax(string $file_path, string $method_name, string $pattern): void
    {
        $error_message = "==========================================\n";
        $error_message .= "FATAL: Invalid route pattern syntax\n";
        $error_message .= "==========================================\n\n";
        $error_message .= "File: {$file_path}\n";
        $error_message .= "Method: {$method_name}\n";
        $error_message .= "Pattern: {$pattern}\n\n";
        $error_message .= "RSX routes use :param syntax, not Laravel's {param} syntax.\n\n";
        $error_message .= "PROBLEM:\n";
        $error_message .= "Your route pattern contains curly braces { or } which are not supported.\n";
        $error_message .= "RSX uses colon-prefixed parameters like :id, :slug, etc.\n\n";
        $error_message .= "INCORRECT:\n";
        $error_message .= "  #[Route('/users/{id}')]\n";
        $error_message .= "  #[Route('/posts/{slug}/edit')]\n\n";
        $error_message .= "CORRECT:\n";
        $error_message .= "  #[Route('/users/:id')]\n";
        $error_message .= "  #[Route('/posts/:slug/edit')]\n\n";
        $error_message .= "WHY THIS MATTERS:\n";
        $error_message .= "- RSX routing system specifically uses :param syntax\n";
        $error_message .= "- The dispatcher expects colon-prefixed parameters\n";
        $error_message .= "- Laravel-style {param} patterns won't be recognized\n\n";
        $error_message .= "FIX:\n";
        $error_message .= "Replace all {param} with :param in your route pattern.\n";
        $error_message .= "==========================================";

        throw new YoureDoingItWrongException($error_message);
    }
}