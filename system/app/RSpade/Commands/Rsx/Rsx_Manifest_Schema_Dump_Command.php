<?php

namespace App\RSpade\Commands\Rsx;

use App\Console\Commands\FrameworkDeveloperCommand;
use App\RSpade\Core\Manifest\Manifest;

class Rsx_Manifest_Schema_Dump_Command extends FrameworkDeveloperCommand
{
    protected $signature = 'rsx:manifest:schema_dump
                            {--no-pretty-print : Output compact JSON without formatting}';

    protected $description = 'Dump the simplified schema structure of the manifest cache for LLM parsing';

    public function handle()
    {
        // Load the manifest
        Manifest::init();
        $manifest = Manifest::get_full_manifest();

        // Simplify the manifest schema
        $simplified = $this->simplify_manifest_data_schema($manifest);

        if ($this->option('no-pretty-print')) {
            // Capture the formatted output
            ob_start();
            $this->print_schema($simplified);
            $json_output = ob_get_clean();

            // Parse and re-encode without formatting
            $data = json_decode($json_output, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error('Failed to parse generated JSON: ' . json_last_error_msg());
                return 1;
            }

            echo json_encode($data);
        } else {
            // Print the simplified schema with formatting
            $this->print_schema($simplified);
        }

        echo "\n";

        return 0;
    }

    /**
     * Simplify manifest data by deduplicating array structures
     */
    private function simplify_manifest_data_schema($data, int $depth = 0, int $max_depth = 20): mixed
    {
        // Prevent infinite recursion (safety limit only)
        if ($depth > $max_depth) {
            return "__max_depth_reached";
        }

        // Handle non-array types - return actual example value
        if (!is_array($data)) {
            return $data; // Return raw value, json_encode will handle escaping
        }

        // Handle empty arrays
        if (empty($data)) {
            return [];
        }

        // Check if this is a numerically indexed array
        $keys = array_keys($data);
        $is_indexed = $keys === range(0, count($keys) - 1);

        if ($is_indexed) {
            // For indexed arrays, deduplicate based on structure
            $structure_groups = [];

            // Group items by structure
            foreach ($data as $item) {
                $structure = $this->create_structure_hash($item);
                $hash = json_encode($structure);

                if (!isset($structure_groups[$hash])) {
                    $structure_groups[$hash] = [];
                }
                $structure_groups[$hash][] = $item;
            }

            $unique_structures = [];
            $total_items = count($data);
            $items_shown = 0;
            $target_min = 3;

            // If only one structure type exists
            if (count($structure_groups) === 1) {
                // Show up to 3 actual different examples
                $group = reset($structure_groups);
                $examples_to_show = min($target_min, count($group));

                for ($i = 0; $i < $examples_to_show; $i++) {
                    $unique_structures[] = $this->simplify_manifest_data_schema($group[$i], $depth + 1, $max_depth);
                    $items_shown++;
                }
            } else {
                // Multiple structure types - show one of each unique structure
                foreach ($structure_groups as $hash => $group) {
                    $unique_structures[] = $this->simplify_manifest_data_schema($group[0], $depth + 1, $max_depth);
                    $items_shown++;

                    // Stop if we've shown enough
                    if ($items_shown >= $target_min) {
                        break;
                    }
                }
            }

            // Add indicator if there are more items
            if ($total_items > $items_shown) {
                $unique_structures[] = "__" . ($total_items - $items_shown) . " more items";
            }

            return $unique_structures;
        } else {
            // For associative arrays, check if ALL values are arrays
            $all_values_are_arrays = true;
            foreach ($data as $value) {
                if (!is_array($value)) {
                    $all_values_are_arrays = false;
                    break;
                }
            }

            // If values are simple types, preserve key-value pairs as-is
            if (!$all_values_are_arrays) {
                $result = [];
                foreach ($data as $key => $value) {
                    $result[$key] = $this->simplify_manifest_data_schema($value, $depth + 1, $max_depth);
                }
                return $result;
            }

            // If all values are arrays, group by structure
            $structure_map = [];

            // Group items by structure hash
            foreach ($data as $key => $value) {
                $structure = $this->create_structure_hash($value);
                $hash = json_encode($structure);

                if (!isset($structure_map[$hash])) {
                    $structure_map[$hash] = [];
                }
                $structure_map[$hash][$key] = $value;
            }

            // Sort structures by frequency (most common first)
            uasort($structure_map, function($a, $b) {
                return count($b) - count($a);
            });

            $result = [];
            $total_items = count($data);
            $unique_structures = count($structure_map);
            $max_structures_to_show = 10; // Show top 10 most common structures
            $target_min = 3;

            // For many items, show strategic sampling
            if ($total_items > 20) {
                $structures_shown = 0;
                $items_represented = 0;

                foreach ($structure_map as $hash => $items) {
                    if ($structures_shown >= $max_structures_to_show) {
                        break;
                    }

                    $key = array_key_first($items);
                    $value = $items[$key];
                    $count = count($items);
                    $items_represented += $count;

                    // Process and add metadata
                    $processed = $this->simplify_manifest_data_schema($value, $depth + 1, $max_depth);

                    // Add count metadata
                    if ($count > 1) {
                        $key_label = $key . " (" . $count . " with this structure)";
                    } else {
                        $key_label = $key . " (unique structure)";
                    }

                    $result[$key_label] = $processed;
                    $structures_shown++;
                }

                // Add summary metadata
                $result["__summary"] = [
                    "total_items" => $total_items,
                    "unique_structures" => $unique_structures,
                    "structures_shown" => $structures_shown,
                    "items_represented" => $items_represented
                ];
            } else {
                // For small collections, show based on uniqueness
                if ($unique_structures > $target_min) {
                    // Many unique structures - show each one
                    foreach ($structure_map as $hash => $items) {
                        $key = array_key_first($items);
                        $value = $items[$key];
                        $count = count($items);

                        $processed = $this->simplify_manifest_data_schema($value, $depth + 1, $max_depth);

                        if ($count > 1) {
                            $result[$key . " (" . $count . " similar)"] = $processed;
                        } else {
                            $result[$key] = $processed;
                        }
                    }
                } else {
                    // Few unique structures, show examples
                    $items_shown = 0;

                    foreach ($structure_map as $hash => $items) {
                        foreach ($items as $key => $value) {
                            if ($items_shown < $target_min) {
                                $result[$key] = $this->simplify_manifest_data_schema($value, $depth + 1, $max_depth);
                                $items_shown++;
                            } else {
                                break 2;
                            }
                        }
                    }

                    // Add indicator if there are more items
                    if ($total_items > $items_shown) {
                        $result["__additional"] = ($total_items - $items_shown) . " more entries";
                    }
                }
            }

            return $result;
        }
    }

    /**
     * Create a structure hash by replacing all values with empty strings
     */
    private function create_structure_hash($data): mixed
    {
        if (!is_array($data)) {
            return '';
        }

        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->create_structure_hash($value);
            } else {
                $result[$key] = '';
            }
        }
        return $result;
    }

    /**
     * Print the simplified schema without colors for LLM parsing
     */
    private function print_schema($data, int $indent = 0): void
    {
        $spaces = str_repeat('  ', $indent);

        if (!is_array($data)) {
            // Use json_encode for all values to ensure proper escaping
            echo json_encode($data);
            return;
        }

        // Handle empty arrays
        if (empty($data)) {
            echo '[]';
            return;
        }

        // Check if indexed or associative
        $keys = array_keys($data);
        $is_indexed = $keys === range(0, count($keys) - 1);

        if ($is_indexed) {
            echo '[';
            $first = true;
            foreach ($data as $item) {
                if (!$first) {
                    echo ',';
                }
                echo "\n" . $spaces . '  ';
                $this->print_schema($item, $indent + 1);
                $first = false;
            }
            echo "\n" . $spaces . ']';
        } else {
            echo '{';
            $first = true;
            foreach ($data as $key => $value) {
                if (!$first) {
                    echo ',';
                }
                // Ensure key is always a quoted string
                echo "\n" . $spaces . '  ' . json_encode((string)$key) . ': ';
                $this->print_schema($value, $indent + 1);
                $first = false;
            }
            echo "\n" . $spaces . '}';
        }
    }
}