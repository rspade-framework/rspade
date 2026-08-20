<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Database\DetailTables\Detail_Tables_Resolver;
use App\RSpade\Core\Manifest\Manifest;

/**
 * DETAIL-01 - validates Class-Table Inheritance $detail_tables declarations.
 *
 * For each model declaring public static $detail_tables it checks:
 *   - exactly one discriminator column (v1 supports a single discriminator)
 *   - each mapped class is a Rsx_Detail_Model_Abstract subclass
 *   - each detail model's $parent_model points back to the declaring base
 *   - no two DISTINCT detail classes derive the same accessor name (ambiguous)
 *
 * Cross-file rule (needs the detail classes too): runs once per rsx:check.
 */
class ModelDetailTables_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'DETAIL-01';
    }

    public function get_name(): string
    {
        return 'Detail Tables Declaration';
    }

    public function get_description(): string
    {
        return 'A model\'s $detail_tables map must use one discriminator column and reference detail models that point back to it';
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

        foreach (Manifest::php_get_extending('Rsx_Model_Abstract') as $entry) {
            $fqcn = $entry['fqcn'] ?? null;
            $base_file = $entry['file'] ?? null;
            if (!$fqcn || !$base_file || !class_exists($fqcn)) {
                continue;
            }

            $detail_tables = $fqcn::$detail_tables ?? null;
            if (empty($detail_tables)) {
                continue;
            }

            $this->__validate_base_model($fqcn, $detail_tables, $base_file);
        }
    }

    private function __validate_base_model(string $fqcn, array $detail_tables, string $base_file): void
    {
        $lines = $this->__file_lines($base_file);
        $line = $this->__find_line($lines, '$detail_tables');

        // One discriminator column only.
        if (count($detail_tables) !== 1) {
            $this->add_violation(
                $base_file,
                $line,
                "Model {$fqcn} \$detail_tables must declare exactly one discriminator column (found " . count($detail_tables) . ")",
                $lines[$line - 1] ?? '',
                "v1 supports a single discriminator column, e.g.:\n" .
                "public static \$detail_tables = ['type_id' => [self::TYPE_PERSON => Party_Person_Detail_Model::class]];"
            );
            return;
        }

        $accessor_to_class = [];

        foreach (Detail_Tables_Resolver::value_map($detail_tables) as $value => $detail_class) {
            // Each mapped class must be a detail model.
            if (!Manifest::php_is_subclass_of($detail_class, 'Rsx_Detail_Model_Abstract')) {
                $this->add_violation(
                    $base_file,
                    $line,
                    "Model {$fqcn} \$detail_tables maps value {$value} to {$detail_class}, which is not a Rsx_Detail_Model_Abstract",
                    $lines[$line - 1] ?? '',
                    "Detail models must extend Rsx_Detail_Model_Abstract."
                );
                continue;
            }

            // The detail model must point back to this base (read via the public accessor,
            // which fails loud when $parent_model is unset).
            if (class_exists($detail_class)) {
                $parent_model = null;
                try {
                    $parent_model = $detail_class::parent_model();
                } catch (\Throwable $e) {
                    $parent_model = null;
                }
                if (!$parent_model || class_basename($parent_model) !== class_basename($fqcn)) {
                    $this->add_violation(
                        $base_file,
                        $line,
                        "Detail model {$detail_class} must declare \$parent_model = {$fqcn}::class (found " . ($parent_model ?: 'null') . ")",
                        $lines[$line - 1] ?? '',
                        "Set: protected static \$parent_model = " . class_basename($fqcn) . "::class; on {$detail_class}."
                    );
                }
            }

            // Accessor-name ambiguity: two DIFFERENT classes deriving the same accessor.
            $accessor = Detail_Tables_Resolver::accessor_name($detail_class);
            if (isset($accessor_to_class[$accessor]) && $accessor_to_class[$accessor] !== $detail_class) {
                $this->add_violation(
                    $base_file,
                    $line,
                    "Model {$fqcn} has two detail models deriving the same accessor '{$accessor}': {$accessor_to_class[$accessor]} and {$detail_class}",
                    $lines[$line - 1] ?? '',
                    "Rename one detail model so the derived accessor names are distinct."
                );
            }
            $accessor_to_class[$accessor] = $detail_class;
        }
    }

    private function __file_lines(string $file_path): array
    {
        $base_path = function_exists('base_path') ? base_path() : '/var/www/html';
        $full_path = str_starts_with($file_path, '/') ? $file_path : $base_path . '/' . $file_path;

        return file_exists($full_path) ? explode("\n", file_get_contents($full_path)) : [];
    }

    private function __find_line(array $lines, string $needle): int
    {
        foreach ($lines as $i => $line) {
            if (str_contains($line, $needle)) {
                return $i + 1;
            }
        }

        return 1;
    }
}
