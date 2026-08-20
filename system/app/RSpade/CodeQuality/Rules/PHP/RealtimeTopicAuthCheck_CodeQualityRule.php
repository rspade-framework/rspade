<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * RealtimeTopicAuthCheckRule - Validates realtime topic classes declare and honor
 * their auth intent.
 *
 * Every class extending Realtime_Topic_Abstract has a $requires_auth static
 * property (default true) and a can_subscribe() method, which is the SOLE
 * runtime enforcement boundary for who may subscribe to a realtime topic
 * (Realtime_Controller has no auth check of its own). This rule catches two
 * distinct mistakes:
 *
 * 1. $requires_auth is true (the default, including topics that never declare
 *    it) but can_subscribe() contains no recognizable auth-check pattern -
 *    almost certainly a topic that will let anyone subscribe despite claiming
 *    otherwise.
 * 2. $requires_auth is explicitly set to false - the topic is intentionally
 *    public. This is allowed, but flagged for mandatory manual review (a public
 *    topic must never leak site/tenant-scoped or otherwise sensitive data),
 *    requiring an explicit exception comment with rationale to suppress.
 *
 * Exemption: add @REALTIME-AUTH-01-EXCEPTION (with rationale) anywhere in the
 * file before the class declaration.
 */
class RealtimeTopicAuthCheck_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'REALTIME-AUTH-01';
    }

    public function get_name(): string
    {
        return 'Realtime Topic Authentication Check';
    }

    public function get_description(): string
    {
        return 'Validates that realtime topic classes (Realtime_Topic_Abstract) have a real auth check in can_subscribe() when $requires_auth is true, and flags public ($requires_auth = false) topics for manual security review';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function is_called_during_manifest_scan(): bool
    {
        return false; // Only run during rsx:check
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Patterns that indicate a real auth check is present in can_subscribe()
     */
    private const AUTH_CHECK_PATTERNS = [
        'Session::is_logged_in',
        'Session::get_user_id',
        'Portal_Session::is_logged_in',
        'Portal_Session::get_portal_user_id',
        'Permission::',
        'Portal_Permission::',
    ];

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        $original_contents = file_get_contents($file_path);

        // File-level exception: suppresses both violation kinds for this topic.
        if (strpos($original_contents, '@' . $this->get_id() . '-EXCEPTION') !== false) {
            return;
        }

        // Only classes extending Realtime_Topic_Abstract.
        if (!isset($metadata['extends']) || $metadata['extends'] !== 'Realtime_Topic_Abstract') {
            return;
        }

        $class_name = $metadata['class'] ?? null;
        if (!$class_name) {
            return;
        }

        if (str_contains($file_path, '/archive/') || str_contains($file_path, '/archived/')) {
            return;
        }

        $can_subscribe_line = $metadata['public_static_methods']['can_subscribe']['line'] ?? 1;

        if ($this->declares_public_topic($original_contents)) {
            $requires_auth_line = $this->find_requires_auth_line($original_contents);

            $this->add_violation(
                $file_path,
                $requires_auth_line,
                "Realtime topic '{$class_name}' is PUBLIC (\$requires_auth = false) - requires manual security review",
                'public static bool $requires_auth = false;',
                $this->build_public_topic_suggestion($class_name),
                'medium'
            );

            return;
        }

        $method_body = $this->extract_method_body($contents, 'can_subscribe');
        if ($method_body && $this->body_has_auth_check($method_body)) {
            return;
        }

        $this->add_violation(
            $file_path,
            $can_subscribe_line,
            "Realtime topic '{$class_name}' requires auth (default) but can_subscribe() has no recognizable auth check",
            'public static function can_subscribe(array $filter = []): bool { ... }',
            $this->build_missing_auth_suggestion($class_name),
            'high'
        );
    }

    private function declares_public_topic(string $contents): bool
    {
        return (bool) preg_match(
            '/public\s+static\s+bool\s+\$requires_auth\s*=\s*false\s*;/',
            $contents
        );
    }

    private function find_requires_auth_line(string $contents): int
    {
        if (preg_match('/public\s+static\s+bool\s+\$requires_auth\s*=\s*false\s*;/', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return substr_count($contents, "\n", 0, $matches[0][1]) + 1;
        }

        return 1;
    }

    private function body_has_auth_check(string $body): bool
    {
        foreach (self::AUTH_CHECK_PATTERNS as $pattern) {
            if (str_contains($body, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function extract_method_body(string $contents, string $method_name): ?string
    {
        $pattern = '/public\s+static\s+function\s+' . preg_quote($method_name, '/') . '\s*\([^)]*\)[^{]*\{/s';

        if (!preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start_pos = $matches[0][1] + strlen($matches[0][0]) - 1;
        $brace_count = 1;
        $pos = $start_pos + 1;
        $length = strlen($contents);

        while ($pos < $length && $brace_count > 0) {
            $char = $contents[$pos];
            if ($char === '{') {
                $brace_count++;
            } elseif ($char === '}') {
                $brace_count--;
            }
            $pos++;
        }

        return substr($contents, $start_pos, $pos - $start_pos);
    }

    private function build_missing_auth_suggestion(string $class_name): string
    {
        $suggestions = [];
        $suggestions[] = "'{$class_name}' requires auth by default but can_subscribe() has no recognizable check.";
        $suggestions[] = "";
        $suggestions[] = "Option 1: Add a real auth check to can_subscribe():";
        $suggestions[] = "    public static function can_subscribe(array \$filter = []): bool";
        $suggestions[] = "    {";
        $suggestions[] = "        return Session::is_logged_in();  // or Portal_Session::, Permission::...";
        $suggestions[] = "    }";
        $suggestions[] = "";
        $suggestions[] = "Option 2: If this topic is intentionally public, declare it explicitly:";
        $suggestions[] = "    public static bool \$requires_auth = false;";
        $suggestions[] = "";
        $suggestions[] = "(then confirm can_subscribe() and the published payload never leak";
        $suggestions[] = " site/tenant-scoped or otherwise sensitive data)";

        return implode("\n", $suggestions);
    }

    private function build_public_topic_suggestion(string $class_name): string
    {
        $suggestions = [];
        $suggestions[] = "'{$class_name}' declares \$requires_auth = false - it is subscribable by ANY";
        $suggestions[] = "caller, authenticated or not.";
        $suggestions[] = "";
        $suggestions[] = "Confirm:";
        $suggestions[] = "  - can_subscribe() does not depend on session state to stay safe";
        $suggestions[] = "  - every Realtime::publish() call for this topic sends only non-sensitive,";
        $suggestions[] = "    non-tenant-scoped data (record IDs and action types, never field values)";
        $suggestions[] = "  - if the topic IS scoped to a tenant, site_id scoping alone is not enough";
        $suggestions[] = "    for a public topic - site_id is derived from hostname for anonymous";
        $suggestions[] = "    callers, not from any real permission check";
        $suggestions[] = "";
        $suggestions[] = "Once reviewed, suppress with a rationale:";
        $suggestions[] = "    // @REALTIME-AUTH-01-EXCEPTION - reviewed 2026-01-01, public weather data only";

        return implode("\n", $suggestions);
    }
}
