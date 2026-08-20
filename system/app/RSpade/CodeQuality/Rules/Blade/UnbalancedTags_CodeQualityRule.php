<?php

namespace App\RSpade\CodeQuality\Rules\Blade;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class UnbalancedTags_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'BLADE-TAGS-01';
    }

    public function get_name(): string
    {
        return 'Balanced HTML Tags in Control Flow';
    }

    public function get_description(): string
    {
        return 'Enforces that HTML tags opened within control flow blocks (@if/@foreach/etc) must be closed within the same block';
    }

    public function get_file_patterns(): array
    {
        return ['*.blade.php'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    public function is_called_during_manifest_scan(): bool
    {
        return false;
    }

    /**
     * Check blade file for unbalanced HTML tags in control flow blocks
     *
     * Simpler rule: If a block's first non-whitespace content is an opening HTML tag,
     * that tag must be closed before the block ends.
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Parse the blade file into control flow blocks
        $blocks = $this->parse_control_flow_blocks($contents);

        foreach ($blocks as $block) {
            $violation = $this->check_first_tag_closed($block['content'], $block['type']);

            if ($violation !== null) {
                $error_message = "Code Quality Violation (BLADE-TAGS-01) - Unbalanced HTML Tags in Control Flow\n\n";
                $error_message .= "CRITICAL: Opening tag in control flow block must be closed within the same block\n\n";
                $error_message .= "File: {$file_path}\n";
                $error_message .= "Block: {$block['type']} at line {$block['start_line']}\n";
                $error_message .= "First tag: <{$violation['tag']}> at line {$violation['opening_line']}\n";
                $error_message .= "Problem: {$violation['message']}\n\n";
                $error_message .= $this->get_remediation($block['type']);

                throw new \App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException(
                    $error_message,
                    0,
                    null,
                    base_path($file_path),
                    $block['start_line'] + $violation['opening_line'] - 1
                );
            }
        }
    }

    /**
     * Parse control flow blocks from blade content
     */
    private function parse_control_flow_blocks(string $contents): array
    {
        $lines = explode("\n", $contents);
        $blocks = [];
        $stack = [];

        // Control flow patterns
        $start_patterns = [
            '@if' => '@endif',
            '@elseif' => '@endif',
            '@else' => '@endif',
            '@foreach' => '@endforeach',
            '@for' => '@endfor',
            '@while' => '@endwhile',
            '@unless' => '@endunless',
            '<?php if' => '<?php endif',
            '<?php foreach' => '<?php endforeach',
            '<?php for' => '<?php endfor',
            '<?php while' => '<?php endwhile',
        ];

        foreach ($lines as $line_num => $line) {
            $line_number = $line_num + 1;

            // Check for block start
            foreach ($start_patterns as $start => $end) {
                if (preg_match('/^\s*' . preg_quote($start, '/') . '\b/', $line)) {
                    // Special handling for @else and @elseif - close previous block first
                    if (in_array($start, ['@elseif', '@else']) && !empty($stack)) {
                        $prev_block = array_pop($stack);
                        $prev_block['end_line'] = $line_number - 1;
                        $blocks[] = $prev_block;
                    }

                    $stack[] = [
                        'type' => $start,
                        'start_line' => $line_number,
                        'content' => '',
                        'lines' => [],
                    ];
                    continue 2;
                }
            }

            // Check for block end
            foreach ($start_patterns as $start => $end) {
                if (preg_match('/^\s*' . preg_quote($end, '/') . '\b/', $line)) {
                    if (!empty($stack)) {
                        $block = array_pop($stack);
                        $block['end_line'] = $line_number;
                        $blocks[] = $block;
                    }
                    continue 2;
                }
            }

            // Add line to current block
            if (!empty($stack)) {
                $stack[count($stack) - 1]['content'] .= $line . "\n";
                $stack[count($stack) - 1]['lines'][$line_number] = $line;
            }
        }

        return $blocks;
    }

    /**
     * Check if the first HTML tag in a block is closed within that block
     * Returns null if OK, or array with violation details if not
     */
    private function check_first_tag_closed(string $content, string $block_type): ?array
    {
        $lines = explode("\n", $content);

        // Void elements that don't need closing tags
        $void_elements = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
                         'link', 'meta', 'param', 'source', 'track', 'wbr'];

        // Find the first non-whitespace line with an opening HTML tag
        $first_tag = null;
        $first_tag_line = null;

        foreach ($lines as $line_num => $line) {
            $trimmed = trim($line);

            // Skip empty lines and blade comments
            if (empty($trimmed) || str_starts_with($trimmed, '{{--')) {
                continue;
            }

            // Look for opening HTML tag at start of line (after whitespace)
            if (preg_match('/^<([a-zA-Z][a-zA-Z0-9_-]*)[^>]*>/i', $trimmed, $match)) {
                $tag_name = strtolower($match[1]);

                // Skip void elements
                if (in_array($tag_name, $void_elements)) {
                    continue;
                }

                // Skip self-closing tags
                if (str_ends_with(trim($match[0]), '/>')) {
                    continue;
                }

                // Found first opening tag
                $first_tag = $tag_name;
                $first_tag_line = $line_num + 1;
                break;
            }

            // If we hit non-tag content first, no violation possible
            if (!empty($trimmed)) {
                return null;
            }
        }

        // If no opening tag found, nothing to check
        if ($first_tag === null) {
            return null;
        }

        // Now check if the closing tag appears in the block
        $closing_tag = "</{$first_tag}>";

        foreach ($lines as $line) {
            if (stripos($line, $closing_tag) !== false) {
                // Found the closing tag - all good
                return null;
            }
        }

        // Closing tag not found in block
        return [
            'tag' => $first_tag,
            'opening_line' => $first_tag_line,
            'message' => "Closing tag </{$first_tag}> not found before end of {$block_type} block",
        ];
    }

    /**
     * Get detailed remediation instructions
     */
    private function get_remediation(string $block_type): string
    {
        return "FRAMEWORK CONVENTION: HTML tags must be balanced within control flow blocks.

This antipattern is commonly called \"Split Tag Conditionals\" or \"Broken HTML Nesting\".

PROBLEM: Control flow statements split what should be a single lexical unit (a complete HTML element).

WHY THIS IS FORBIDDEN:
1. Parser confusion - Syntax highlighters and formatters can't parse it correctly
2. Maintainability nightmare - Hard to see complete element structure
3. Error-prone - Easy to create invalid HTML when modifying
4. Mental overhead - Reader must mentally reconstruct tags across control flow

CORRECT ALTERNATIVES:

Option 1: Inline ternary (best for simple cases)
------------------------------------------------
@if(\$active)
    <div class=\"card active\">Content</div>
@else
    <div class=\"card inactive\">Content</div>
@endif

Option 2: Pre-compute classes (best for complex attributes)
------------------------------------------------------------
@php
    \$card_class = \$active ? 'active' : 'inactive';
@endphp
<div class=\"card {{ \$card_class }}\">
    Content
</div>

Option 3: Duplicate complete tags (best when tags differ significantly)
------------------------------------------------------------------------
@if(\$show_form)
    <form action=\"/submit\" method=\"POST\">
        <input type=\"text\" name=\"value\">
        <button>Submit</button>
    </form>
@else
    <div class=\"info-message\">
        Form is disabled
    </div>
@endif

[ERROR] NEVER DO THIS:
----------------
@if(\$required)
    <select required>
@else
    <select>
@endif
        <option>Value</option>
    </select>

[OK] CORRECT:
-----------
@if(\$required)
    <select required>
        <option>Value</option>
    </select>
@else
    <select>
        <option>Value</option>
    </select>
@endif

PRINCIPLE: Control flow should NEVER split lexical units. HTML elements are atomic structures - keep them whole.";
    }
}
