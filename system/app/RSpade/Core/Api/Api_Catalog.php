<?php

namespace App\RSpade\Core\Api;

use App\RSpade\Core\Manifest\Manifest;

/**
 * Api_Catalog - read model over the finalized #[Api_Endpoint] manifest catalog.
 *
 * Pure functions over the manifest 'api_endpoints' table (populated at scan time by
 * Api_Endpoint_ManifestSupport). This is the single implementation of the version
 * display/resolution rule the documentation UI and the LLM catalog both consume.
 *
 * Display/resolution rule: endpoints are grouped by (HTTP verb, path key = pattern with
 * /api/vN stripped). For a selected catalog version, each group shows the single endpoint
 * with the highest version <= the selected version, at its REAL /api/vN pattern. Runtime
 * routing is always exact-match on the full pattern and is unaffected by this rule.
 */
class Api_Catalog
{
    /**
     * Flat list of all API endpoints from the manifest catalog.
     *
     * @param bool $include_hidden Include endpoints marked @api-hidden.
     */
    public static function get_endpoint_list(bool $include_hidden = false): array
    {
        $manifest = Manifest::get_full_manifest();
        $endpoints = $manifest['data']['api_endpoints'] ?? [];

        if (!$include_hidden) {
            $endpoints = array_filter($endpoints, fn($ep) => !$ep['hidden']);
        }

        return array_values($endpoints);
    }

    /**
     * Distinct version numbers present across all endpoints, sorted descending.
     */
    public static function get_versions(): array
    {
        $versions = [];
        foreach (static::get_endpoint_list(true) as $ep) {
            $versions[$ep['version']] = true;
        }

        $versions = array_keys($versions);
        rsort($versions);

        return array_values($versions);
    }

    /**
     * Resolve the endpoints visible for a selected catalog version, grouped by resource.
     *
     * Endpoints are grouped by (verb, path_key); each group contributes the single
     * highest-version endpoint whose version <= $version (groups with none are skipped).
     * Winners are deduped by (class, method, pattern) so a GET+POST endpoint appears once
     * when both verbs resolve to it, then grouped by resource name (ksorted).
     */
    public static function resolve_for_version(int $version, bool $include_hidden = false): array
    {
        $endpoints = static::get_endpoint_list($include_hidden);

        // Group by (verb, path_key).
        $groups = [];
        foreach ($endpoints as $ep) {
            foreach ($ep['methods'] as $verb) {
                $key = $verb . ' ' . $ep['path_key'];
                $groups[$key][] = $ep;
            }
        }

        // Pick the highest version <= $version per group, dedupe across groups.
        $winners = [];
        foreach ($groups as $group_endpoints) {
            $best = null;
            foreach ($group_endpoints as $ep) {
                if ($ep['version'] > $version) {
                    continue;
                }
                if ($best === null || $ep['version'] > $best['version']) {
                    $best = $ep;
                }
            }
            if ($best === null) {
                continue;
            }
            $winners[static::_dedupe_key($best)] = $best;
        }

        // Group winners by resource for display.
        $grouped = [];
        foreach ($winners as $ep) {
            $resource = static::_resource_name($ep['class']);
            if (!isset($grouped[$resource])) {
                $grouped[$resource] = [
                    'name' => str_replace('_', ' ', $resource),
                    'class' => $ep['class'],
                    'endpoints' => [],
                ];
            }
            $grouped[$resource]['endpoints'][] = $ep;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * Machine-readable dump of the entire catalog for LLM consumption.
     *
     * Includes ALL non-hidden endpoints across every version, each annotated with a
     * latest_in_version map (version => whether this endpoint is the resolved pick for
     * that catalog version).
     */
    public static function get_llm_catalog(): array
    {
        $versions = static::get_versions();

        // Precompute the resolved winner set (dedupe keys) for each catalog version.
        $winners_by_version = [];
        foreach ($versions as $v) {
            $set = [];
            foreach (static::resolve_for_version($v) as $group) {
                foreach ($group['endpoints'] as $ep) {
                    $set[static::_dedupe_key($ep)] = true;
                }
            }
            $winners_by_version[$v] = $set;
        }

        $out_endpoints = [];
        foreach (static::get_endpoint_list(false) as $ep) {
            $key = static::_dedupe_key($ep);
            $latest = [];
            foreach ($versions as $v) {
                $latest[$v] = isset($winners_by_version[$v][$key]);
            }

            $out_endpoints[] = [
                'pattern' => $ep['pattern'],
                'methods' => $ep['methods'],
                'version' => $ep['version'],
                'path_key' => $ep['path_key'],
                'description' => $ep['description'],
                'api_params' => $ep['api_params'],
                'response_example' => $ep['response_example'],
                'latest_in_version' => $latest,
            ];
        }

        return [
            'generated_for' => 'llm',
            'version_resolution' => 'Endpoints are grouped by HTTP verb and path key (the pattern with its /api/vN prefix removed). When a catalog version is selected, each group displays only the single endpoint whose version is the newest at or below the selected version, presented at its real /api/vN path; older versions of the same verb+path are hidden from that view. This display rule affects documentation only. Runtime request routing is always an exact match on the full /api/vN pattern, so every listed version remains individually callable regardless of which catalog version is being viewed.',
            'versions' => $versions,
            'endpoints' => $out_endpoints,
        ];
    }

    /**
     * Parse the integer version from an API pattern (e.g. '/api/v2/x' -> 2).
     *
     * Twin of the local regex in Api_Endpoint_ManifestSupport (which runs before this
     * class is guaranteed loadable during the manifest build).
     */
    public static function parse_version(string $pattern): int
    {
        if (preg_match('#^/api/v([0-9]+)#', $pattern, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * Strip the leading /api/vN from a pattern to produce the verb-agnostic path key
     * (e.g. '/api/v1/contacts/:id' -> '/contacts/:id').
     */
    public static function path_key(string $pattern): string
    {
        return preg_replace('#^/api/v[0-9]+#', '', $pattern);
    }

    /**
     * Dedupe identity for an endpoint across verb groups: class + method + pattern.
     */
    private static function _dedupe_key(array $endpoint): string
    {
        return $endpoint['class'] . '::' . $endpoint['method'] . '::' . $endpoint['pattern'];
    }

    /**
     * Derive a resource name from a controller class by stripping the controller suffix.
     */
    private static function _resource_name(string $class): string
    {
        if (str_ends_with($class, '_Api_Controller')) {
            return substr($class, 0, -strlen('_Api_Controller'));
        }
        if (str_ends_with($class, '_Controller')) {
            return substr($class, 0, -strlen('_Controller'));
        }

        return $class;
    }
}
