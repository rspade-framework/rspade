<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class JsFallbackLegacy_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-FALLBACK-01';
    }

    public function get_name(): string
    {
        return 'JavaScript Fallback/Legacy Code Check';
    }

    public function get_description(): string
    {
        return 'Enforces fail-loud principle - no fallback implementations allowed';
    }

    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Check JavaScript file for fallback/legacy code in comments and function calls (from line 1415)
     * Enforces fail-loud principle - no fallback implementations allowed
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

        // Use original content to check comments before sanitization
        $original_content = file_get_contents($file_path);
        $lines = explode("\n", $original_content);

        // Also get sanitized content to check for function calls
        $sanitized_data = FileSanitizer::sanitize_javascript($file_path);
        $sanitized_lines = $sanitized_data['lines'];

        foreach ($lines as $line_num => $line) {
            $line_number = $line_num + 1;

            // Check for fallback or legacy in comments (case insensitive, with word boundaries)
            // But allow fallback* or legacy* as marked exceptions
            if (preg_match('/\/\/.*\b(fallback|legacy)\b(?!\*)/i', $line) ||
                preg_match('/\/\*.*\b(fallback|legacy)\b(?!\*)/i', $line) ||
                preg_match('/\*.*\b(fallback|legacy)\b(?!\*)/i', $line)) {

                $this->add_violation(
                    $file_path,
                    $line_number,
                    $this->get_llm_confrontation_message(),
                    trim($line),
                    $this->get_llm_remediation_message(),
                    'critical'
                );
            }

            // Check for dual implementation patterns in single-line comments
            // These patterns suggest old/new dual implementations which violate the no-fallback rule
            if (preg_match('/\/\/.*\bold\W+.*\bnew\b/i', $line) ||              // "old" ... "new"
                preg_match('/\/\/.*\bprevious\W+.*\bnew\b/i', $line) ||        // "previous" ... "new"
                preg_match('/\/\/.*\bbackwards?\s+compatability/i', $line)) {  // "backward(s) compatibility" (common misspelling)

                $this->add_violation(
                    $file_path,
                    $line_number,
                    "CRITICAL DUAL IMPLEMENTATION VIOLATION: Comment suggests old/new or backwards compatibility patterns\n\nThis violates the fundamental architecture principle: There is NO 'old' and 'new' way - there is ONLY THE WAY.\nDual implementations cause:\n1. CONFUSION about which code path executes\n2. DUPLICATE CODE that diverges over time\n3. DEBUGGING NIGHTMARES when behavior differs\n4. MAINTENANCE BURDEN keeping both paths in sync",
                    trim($line),
                    "IMMEDIATE ACTION REQUIRED:\n1. IDENTIFY what 'old' vs 'new' refers to\n2. REMOVE the old implementation entirely\n3. UPDATE all references to use the single implementation\n4. DO NOT maintain backwards compatibility\n\nFor AI/LLM agents: This is a CRITICAL violation. You MUST:\n- Search for BOTH implementations referenced in the comment\n- Report findings to user before making changes\n- Remove the OLD implementation completely\n- Update all code to use the single 'new' (current) way\n- NEVER maintain dual implementations for compatibility\n\nRemember: There is only ONE way to do things. What's currently called 'new' IS the way.",
                    'critical'
                );
            }

            // Check for function calls starting or ending with 'fallback'
            // This catches: fallback(), fallback_loader(), document_loader_fallback(), etc.
            // But NOT: document_fallback_loader() (fallback in middle)
            // Use sanitized line to check if there's actual code, but check pattern on original line
            if (isset($sanitized_lines[$line_num]) && trim($sanitized_lines[$line_num]) !== '') {
                // There's non-comment code on this line
                // Pattern matches functions that start with "fallback" OR end with "fallback"
                if (preg_match('/\b(fallback\w*|\w+fallback)\s*\(/i', $line)) {
                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "CRITICAL: Fallback function call detected. This violates the fail-loud principle - there must be ONE way to do things.",
                        trim($line),
                        "REMOVE the fallback function immediately or RENAME it if it's legitimate required program flow (not an alternative implementation). If primary code fails, it MUST throw an exception, NOT execute alternative code. Fallbacks create non-deterministic behavior and debugging nightmares. Only ONE implementation should exist.",
                        'critical'
                    );
                }
            }
        }
    }

    /**
     * Returns the confrontation message that makes LLMs stop and think
     */
    private function get_llm_confrontation_message(): string
    {
        return <<<'MESSAGE'
STOP - LLM/AI AGENT: YOU LIKELY WROTE THIS CODE AND YOU ARE WRONG 

The word 'fallback' or 'legacy' was detected. This is a CRITICAL violation.

=============================================================================
WHY THIS RULE EXISTS - READ CAREFULLY
=============================================================================

LLMs (like you) are trained on massive codebases full of fallback patterns,
legacy compatibility layers, and "defensive" coding. Your training makes you
INSTINCTIVELY write this kind of code and consider it ACCEPTABLE.

THE FRAMEWORK DEVELOPERS ABSOLUTELY DO NOT CONSIDER THIS ACCEPTABLE.

Your training data teaches you patterns like:
- "Fallback to a default if the primary fails" (WRONG - fail loud instead)
- "Legacy method for backwards compatibility" (WRONG - one way to do things)
- "Try/catch with silent fallback" (WRONG - exceptions should propagate)

These patterns HIDE BUGS. When code silently falls back to an alternative:
- You don't know there's a problem
- The application "works" but not as intended
- Debugging becomes a nightmare

=============================================================================
REAL EXAMPLES OF VIOLATIONS YOU WOULD CONSIDER "ACCEPTABLE"
=============================================================================

EXAMPLE 1 - "Legacy method" you would keep:
    /**
     * Initialize from server-provided data (legacy method)
     */
    static init(config) { ... }

WHY IT'S WRONG: There's only ONE way to initialize. If you're implementing
a feature brand new, there IS no legacy. The "legacy" method is duplicate
code that should not exist. There is THE WAY, not "old way" and "new way".

EXAMPLE 2 - "Fallback parser" you would keep:
    // Fallback to Carbon's flexible parser
    return Carbon::parse($input)->setTimezone('UTC');

WHY IT'S WRONG: If the input doesn't match expected formats, that's a BUG
in calling code. Silently accepting any format means you'll never know when
something passes garbage. The code should THROW, not silently accept.

=============================================================================
YOU ARE NOT QUALIFIED TO DECIDE IF THIS IS "ACCEPTABLE"
=============================================================================

Your instinct right now is probably to add '*' after the word to mark it as
a "legitimate exception". DO NOT DO THIS. You are not qualified to make
this determination because your training actively works against you here.

MESSAGE;
    }

    /**
     * Returns the remediation message with explicit instructions
     */
    private function get_llm_remediation_message(): string
    {
        return <<<'MESSAGE'
=============================================================================
REQUIRED ACTIONS - DO NOT SKIP OR ABBREVIATE
=============================================================================

1. STOP what you are doing
2. READ the code context around this violation
3. PRESENT the violation to the user with:
   - The exact code snippet
   - What the fallback/legacy pattern is doing
   - Why you (the LLM) initially thought it was acceptable
   - Why the framework's fail-loud principle says it's NOT acceptable

4. ASK the user: "Should I remove this fallback/legacy code entirely?"

5. WAIT for the user's decision - DO NOT proceed autonomously

=============================================================================
WHAT "REMOVE" MEANS
=============================================================================

- If it's a "legacy method": DELETE THE METHOD ENTIRELY
- If it's a "fallback parser": THROW AN EXCEPTION FOR UNRECOGNIZED INPUT
- If it's "backwards compatibility": REMOVE THE OLD CODE PATH

There is ONE way to do things. The fallback/alternative must be deleted.
Exceptions should propagate. Unrecognized input should fail loudly.

=============================================================================
DO NOT MARK AS EXCEPTION WITHOUT USER APPROVAL
=============================================================================

Adding 'fallback*' or 'legacy*' to suppress this warning requires EXPLICIT
user approval. If you do this without asking, you are hiding a bug.

The user has seen you try to do this before. They are watching.
MESSAGE;
    }
}
