<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * TASK-CONCURRENCY-01 - validates task concurrency attributes.
 *
 * A task method's concurrency policy is EITHER #[Exclusive] OR #[Debounce(seconds)]
 * OR neither - never both (they are the same mechanism with a 0 vs N delay, so
 * setting both is contradictory). Also validates that #[Debounce] carries a single
 * non-negative integer delay.
 *
 * Cross-file rule: runs once per rsx:check over the whole manifest.
 */
class TaskConcurrencyAttributes_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'TASK-CONCURRENCY-01';
    }

    public function get_name(): string
    {
        return 'Task Concurrency Attributes';
    }

    public function get_description(): string
    {
        return 'A task is Exclusive OR Debounce(seconds) OR neither - never both; Debounce takes one non-negative integer';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    public function is_incremental(): bool
    {
        return false;
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        static $already_checked = false;
        if ($already_checked) {
            return;
        }
        $already_checked = true;

        foreach (Manifest::get_all() as $info) {
            $methods = $info['public_static_methods'] ?? null;
            $base_file = $info['file'] ?? null;
            if (!$methods || !$base_file) {
                continue;
            }

            foreach ($methods as $method_name => $method_info) {
                $attrs = $method_info['attributes'] ?? [];
                if (empty($attrs)) {
                    continue;
                }

                $has_exclusive = $this->__has_attr($attrs, 'Exclusive');
                $debounce_args = $this->__attr_args($attrs, 'Debounce');
                $has_debounce = $debounce_args !== null;

                if ($has_exclusive && $has_debounce) {
                    $this->__violate(
                        $base_file,
                        $method_name,
                        "Task {$method_name} declares both #[Exclusive] and #[Debounce] - they are mutually exclusive.",
                        "A task is EITHER #[Exclusive] (run one at a time, no delay) OR #[Debounce(seconds)] " .
                        "(same, but the coalesced follow-up waits N seconds) OR neither. Remove one."
                    );
                    continue;
                }

                if ($has_debounce) {
                    $delay = $debounce_args[0] ?? null;
                    if (!is_int($delay) || $delay < 0) {
                        $this->__violate(
                            $base_file,
                            $method_name,
                            "Task {$method_name} #[Debounce] must take one non-negative integer number of seconds.",
                            "Use e.g. #[Debounce(30)]."
                        );
                    }
                }
            }
        }
    }

    private function __has_attr(array $attrs, string $name): bool
    {
        foreach ($attrs as $attr_name => $instances) {
            if ($attr_name === $name || str_ends_with($attr_name, '\\' . $name)) {
                return true;
            }
        }

        return false;
    }

    private function __attr_args(array $attrs, string $name): ?array
    {
        foreach ($attrs as $attr_name => $instances) {
            if ($attr_name === $name || str_ends_with($attr_name, '\\' . $name)) {
                return $instances[0] ?? [];
            }
        }

        return null;
    }

    private function __violate(string $file, string $method, string $message, string $fix): void
    {
        $lines = $this->__file_lines($file);
        $line = 1;
        foreach ($lines as $i => $text) {
            if (str_contains($text, "function {$method}")) {
                $line = $i + 1;
                break;
            }
        }

        $this->add_violation($file, $line, $message, $lines[$line - 1] ?? '', $fix);
    }

    private function __file_lines(string $file_path): array
    {
        $base_path = function_exists('base_path') ? base_path() : '/var/www/html';
        $full_path = str_starts_with($file_path, '/') ? $file_path : $base_path . '/' . $file_path;

        return file_exists($full_path) ? explode("\n", file_get_contents($full_path)) : [];
    }
}
