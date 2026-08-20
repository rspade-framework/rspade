<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class PhpFallbackLegacy_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-FALLBACK-01';
    }

    public function get_name(): string
    {
        return 'PHP Fallback/Legacy Code Check';
    }

    public function get_description(): string
    {
        return 'Enforces fail-loud principle - no fallback implementations allowed';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Check PHP file for fallback/legacy code in comments and function calls (from line 1474)
     * Enforces fail-loud principle - no fallback implementations allowed
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor directories
        if (str_contains($file_path, '/vendor/')) {
            return;
        }

        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }

        // Skip RsxCheckCommand.php - it documents what the checks do
        if (str_contains($file_path, 'RsxCheckCommand.php')) {
            return;
        }

        // Use original content to check comments
        $original_content = file_get_contents($file_path);
        $lines = explode("\n", $original_content);

        foreach ($lines as $line_num => $line) {
            $line_number = $line_num + 1;

            // Skip line if it's entirely a comment (starts with // after whitespace)
            $trimmed_line = trim($line);
            $is_line_comment = str_starts_with($trimmed_line, '//');

            // Check for fallback or legacy in comments (case insensitive, with word boundaries)
            // But allow fallback* or legacy* as marked exceptions
            if (preg_match('/\/\/.*\b(fallback|legacy)\b(?!\*)/i', $line) ||
                preg_match('/\/\*.*\b(fallback|legacy)\b(?!\*)/i', $line) ||
                preg_match('/\*.*\b(fallback|legacy)\b(?!\*)/i', $line) ||
                preg_match('/#.*\b(fallback|legacy)\b(?!\*)/i', $line)) {  // Also check # comments

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
            // This catches: fallback(), fallback_loader(), $this->loader_fallback(), etc.
            // But NOT: $this->document_fallback_loader() (fallback in middle)
            if (!$is_line_comment) {
                // Remove inline comments before checking
                $code_part = preg_replace('/\/\/.*$/', '', $line);
                $code_part = preg_replace('/\/\*.*?\*\//', '', $code_part);

                // Check if function starting or ending with "fallback" exists in non-comment part
                if (preg_match('/\b(fallback\w*|\w+fallback)\s*\(/i', $code_part)) {
                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "CRITICAL: Fallback function call detected - INVESTIGATE IMMEDIATELY\n\nThis function name suggests duplicate implementation or legacy code path.",
                        trim($line),
                        "REQUIRED ACTIONS:\n1. TRACE where this function is defined and what calls it\n2. CHECK if this is handling a deprecated code path\n3. VERIFY no duplicate implementations exist\n4. If legitimate (rare), rename to describe actual purpose\n\nFor AI/LLM: STOP and investigate before ANY changes. Search for:\n- Function definition\n- All callers of this function\n- Related implementations\n\nNEVER just rename without understanding the full context.",
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
