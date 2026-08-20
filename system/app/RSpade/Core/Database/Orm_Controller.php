<?php

namespace App\RSpade\Core\Database;

use Exception;
use Illuminate\Http\Request;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Database\Orm_Fetch_Preload;
use App\RSpade\Core\Manifest\Manifest;

/**
 * ORM Controller for JavaScript model fetch operations
 *
 * Provides Ajax endpoints for the JavaScript ORM (Rsx_Js_Model) to fetch
 * model records. Uses the standard #[Ajax_Endpoint] pattern to ensure
 * proper request/response wrapping.
 *
 * SECURITY NOTICE: This controller is a major data egress point in the system.
 * It will be a target for vulnerability scanning and enumeration attacks.
 * All methods must be written defensively to prevent information disclosure.
 *
 * AUTHORIZATION: the class gate is 'public' because this controller is the generic
 * ORM transport, not a data surface of its own. Every request is gated inside, on
 * the TARGET model member: _fetch_gates_pass() evaluates the #[Auth] gates the
 * model's fetch()/portal_fetch()/relationship declares, and the model's own body
 * still applies its record-level rules. A denial is reported as "not found" like
 * any other unresolvable record (anti-enumeration).
 */
#[Auth('public')]
#[Auth_Realm('any')]
class Orm_Controller extends Rsx_Controller_Abstract
{
    /**
     * Fetch model records by ID.
     *
     * Called by Rsx_Js_Model.fetch() / fetch_or_null() in JavaScript, which batch every
     * lookup issued in one synchronous turn into a single call here. The model must have
     * the #[Ajax_Endpoint_Model_Fetch] attribute on its fetch() method.
     *
     * WIRE CONTRACT
     *   in:  {model: string, ids: int[]}   1..rsx.model_fetch.batch_max_ids ids
     *   out: {records: {"<id>": record, ...}}
     *
     * A requested id is present in `records` when it resolved, and simply ABSENT when it
     * did not. Absence is the ONE answer for every unresolvable id - the row does not
     * exist, the model's own fetch() refused it, or the surface's gates denied this
     * caller - so a prober cannot tell those apart (the anti-enumeration property the
     * single-id endpoint got from its one generic "Record not found"). There is no
     * per-id reason and no per-id error code. The throw-or-null decision is the CLIENT's
     * (fetch() throws, fetch_or_null() returns null); it never rode the wire.
     *
     * BATCH-LEVEL failures are still errors, because they are about the REQUEST rather
     * than about any record:
     * - missing model / missing or malformed ids / over the id cap -> ERROR_VALIDATION
     * - unknown model, non-model class -> ERROR_NOT_FOUND, one identical message
     * - model without the fetch attribute -> ERROR_UNAUTHORIZED (deliberately verbose)
     *
     * SECURITY: This endpoint is a primary target for enumeration attacks.
     * Error responses MUST be identical regardless of failure reason to prevent
     * attackers from discovering valid class names or system architecture:
     * - Class doesn't exist → "Model not found"
     * - Class exists but isn't a model → "Model not found"
     * - Model exists but missing attribute → "Model not found"
     * All failures return the same generic error to prevent information leakage.
     *
     * @param Request $request
     * @param array $params Expected: model (string), ids (array of int)
     * @return mixed The records map, or an error response
     */
    #[Ajax_Endpoint]
    public static function fetch(Request $request, array $params = [])
    {
        $model_name = $params['model'] ?? null;
        $ids = $params['ids'] ?? null;

        if (!$model_name) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION, 'Model name is required');
        }

        if (!is_array($ids) || empty($ids)) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION, 'ids parameter is required');
        }

        // Distinct ids only. The client already sends distinct ids (it dedups every
        // in-flight lookup), so this is a belt: it keeps the cap honest about how much
        // work was actually requested, and keeps the records map keyed once per id.
        $distinct_ids = [];
        foreach ($ids as $raw_id) {
            if (!is_scalar($raw_id) || !is_numeric($raw_id)) {
                return response_error(
                    \App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION,
                    'ids must contain only numeric record ids'
                );
            }

            $distinct_ids[(int) $raw_id] = (int) $raw_id;
        }
        $distinct_ids = array_values($distinct_ids);

        // REFUSE an oversized request, never truncate it. A silently-partial answer is
        // indistinguishable from "those records do not exist", which is exactly the
        // signal this endpoint's absence-means-nothing contract depends on.
        $cap = (int) config('rsx.model_fetch.batch_max_ids');
        if (count($distinct_ids) > $cap) {
            return response_error(
                \App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION,
                'Too many ids in one fetch request: ' . count($distinct_ids) . ' requested, '
                . "the limit is {$cap} (rsx.model_fetch.batch_max_ids). Split the set into "
                . 'multiple requests.'
            );
        }

        // Look up the PHP model class from manifest
        try {
            $model_metadata = Manifest::php_get_metadata_by_class($model_name);
        } catch (\Exception $e) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Model {$model_name} not found");
        }

        $model_class = $model_metadata['fqcn'] ?? null;
        if (!$model_class) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Model {$model_name} not found");
        }

        // Verify it's a subclass of Rsx_Model_Abstract (security check)
        // SECURITY: Use same error message as "not found" to prevent enumeration
        if (!Manifest::php_is_subclass_of($model_name, 'Rsx_Model_Abstract')) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Model {$model_name} not found");
        }

        // Portal requests resolve to the model's portal_fetch() (portal authorization
        // contract), regular requests to fetch() (staff authorization contract). There
        // is one ORM endpoint; the method name is chosen by request context.
        $fetch_method_name = static::_resolve_fetch_method_name();

        // Check if the fetch method has the Ajax_Endpoint_Model_Fetch attribute
        // SECURITY EXCEPTION: This error reveals the model exists but lacks the attribute.
        // This is acceptable because: (1) attacker already knows model name from client code,
        // (2) detailed message is essential for developers diagnosing configuration issues,
        // (3) knowing a model lacks fetch() doesn't expose exploitable information.
        $has_fetch_attribute = false;
        if (isset($model_metadata['public_static_methods'][$fetch_method_name])) {
            $fetch_method = $model_metadata['public_static_methods'][$fetch_method_name];
            if (isset($fetch_method['attributes']['Ajax_Endpoint_Model_Fetch'])) {
                $has_fetch_attribute = true;
            }
        }

        if (!$has_fetch_attribute) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_UNAUTHORIZED, "Model {$model_name} {$fetch_method_name}() method missing Ajax_Endpoint_Model_Fetch attribute");
        }

        // --- Declarative #[Auth] gates ---
        // The framework authorization seam the generic ORM path previously lacked:
        // gates run BEFORE any model code, ONCE for the whole request (the gate list is
        // user-scoped, so it cannot differ per id). A denial yields an EMPTY records map:
        // every requested id is absent, which is byte-identical to a request whose ids
        // all happen to name no row. That is the same indistinguishability the single-id
        // endpoint bought with its one generic "Record not found". Record-level narrowing
        // stays inside fetch()/portal_can_read().
        if (!static::_fetch_gates_pass($model_name, $fetch_method_name)) {
            return ['records' => []];
        }

        $records = [];

        try {
            // ONE query for every requested id, held in request RAM for the loop below.
            // The fetch() bodies are unchanged and unaware: their `static::find($id)`
            // resolves out of the preload (RestrictedEloquentBuilder::find), so N ids
            // cost one SELECT instead of N. A body whose lookup is constrained or
            // scope-stripped simply misses the preload and runs its own query.
            Orm_Fetch_Preload::populate(
                $model_class,
                $model_class::whereIn('id', $distinct_ids)->get()
            );

            foreach ($distinct_ids as $id) {
                $result = $model_class::$fetch_method_name($id);

                // Not found / refused by the model: leave the id out of the map.
                if ($result === false || $result === null) {
                    continue;
                }

                // Validate array returns include __MODEL for JavaScript hydration
                if (is_array($result) && !isset($result['__MODEL'])) {
                    throw new \RuntimeException(
                        "Model {$model_name}::fetch() returned an array without __MODEL property. " .
                        "JavaScript ORM requires __MODEL for class hydration. " .
                        "Fix: Call \$model->toArray() on the Eloquent model first, then augment the array before returning. " .
                        "Example: \$data = \$model->toArray(); \$data['computed_field'] = 'value'; return \$data;"
                    );
                }

                // Keyed by record id. PHP coerces the numeric-string key back to an int,
                // which is harmless: record ids are always >= 1, so the key set is never
                // the 0..n-1 run that would make json_encode emit an ARRAY instead of an
                // object. The client aligns on String(id).
                $records[(string) $id] = $result;
            }
        } finally {
            Orm_Fetch_Preload::clear();
        }

        return ['records' => $records];
    }

    /**
     * Fetch related records via a model's relationship method
     *
     * Called by JavaScript relationship methods in Base_*_Model stubs.
     * Validates source model is fetchable, then calls the relationship,
     * and passes each related record through its own fetch() for security.
     *
     * Flow:
     * 1. Validate source model has fetch attribute
     * 2. Call source model's fetch() to verify access to parent record
     * 3. Call relationship method to get related IDs
     * 4. For each related record, call its model's fetch() for security
     * 5. Return hydrated results (single object or array)
     *
     * @param Request $request
     * @param array $params Expected: model (string), id (int), relationship (string)
     * @return mixed Related model data or false
     */
    #[Ajax_Endpoint]
    public static function fetch_relationship(Request $request, array $params = [])
    {
        $model_name = $params['model'] ?? null;
        $id = $params['id'] ?? null;
        $relationship_name = $params['relationship'] ?? null;

        // Validate required parameters
        if (!$model_name) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION, 'Model name is required');
        }
        if ($id === null) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION, 'ID parameter is required');
        }
        if (!$relationship_name) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION, 'Relationship name is required');
        }

        // Look up the PHP model class from manifest
        try {
            $model_metadata = Manifest::php_get_metadata_by_class($model_name);
        } catch (\Exception $e) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Model not found");
        }

        $model_class = $model_metadata['fqcn'] ?? null;
        if (!$model_class) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Model not found");
        }

        // Verify it's a subclass of Rsx_Model_Abstract
        if (!Manifest::php_is_subclass_of($model_name, 'Rsx_Model_Abstract')) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Model not found");
        }

        // Portal requests resolve to portal_fetch() (portal authorization contract),
        // regular requests to fetch() (staff authorization contract).
        $fetch_method_name = static::_resolve_fetch_method_name();

        // Check if the fetch method has the Ajax_Endpoint_Model_Fetch attribute
        $has_fetch_attribute = false;
        if (isset($model_metadata['public_static_methods'][$fetch_method_name])) {
            $fetch_method = $model_metadata['public_static_methods'][$fetch_method_name];
            if (isset($fetch_method['attributes']['Ajax_Endpoint_Model_Fetch'])) {
                $has_fetch_attribute = true;
            }
        }

        if (!$has_fetch_attribute) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_UNAUTHORIZED, "Model {$fetch_method_name}() not available");
        }

        // Verify the relationship exists and is fetchable
        // Check if method has #[Relationship] attribute
        $has_relationship_attr = false;
        $has_fetch_attr_on_rel = false;

        if (isset($model_metadata['public_instance_methods'][$relationship_name])) {
            $rel_method = $model_metadata['public_instance_methods'][$relationship_name];
            $has_relationship_attr = isset($rel_method['attributes']['Relationship']);
            $has_fetch_attr_on_rel = isset($rel_method['attributes']['Ajax_Endpoint_Model_Fetch']);
        }

        if (!$has_relationship_attr) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "No such relationship: {$relationship_name}");
        }

        if (!$has_fetch_attr_on_rel) {
            return response_error(
                \App\RSpade\Core\Ajax\Ajax::ERROR_UNAUTHORIZED,
                "Relationship '{$relationship_name}' does not have the #[Ajax_Endpoint_Model_Fetch] attribute. " .
                "Add this attribute to the relationship method before it can be fetched from JavaScript."
            );
        }

        // --- Declarative #[Auth] gates ---
        // Two surfaces are about to be used: the source model's (portal_)fetch(), and
        // the relationship method itself. Both are indexed surfaces with their own
        // gate lists (each already merged with the model's class-level #[Auth]), and
        // both must pass before any model code runs. A denial is reported as the
        // generic "not found" the missing-record path uses (anti-enumeration).
        if (!static::_fetch_gates_pass($model_name, $fetch_method_name)
            || !static::_fetch_gates_pass($model_name, $relationship_name)) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Record not found or access denied");
        }

        // Fetch the source record using its (portal_)fetch() method (security check)
        // This validates that the current user has access to this record.
        $fetch_result = $model_class::$fetch_method_name($id);
        if (!$fetch_result) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Record not found or access denied");
        }

        // fetch() may return an Eloquent model OR an augmented array.
        // If it's an array, we need to verify access was granted and re-fetch
        // the actual Eloquent model to call relationship methods on it.
        //
        // INEFFICIENCY NOTE: when fetch() returns an array, this source record costs two
        // queries - one inside fetch() for the access check, one here for the Eloquent
        // instance the relationship methods need. The batch preload does NOT help: it can
        // only serve rows it already loaded, and loading this one row would itself be the
        // extra query. It is a fixed two-query cost for ONE record, not a per-id fan-out
        // (the plural branch below, which was the real cost, is preloaded).
        if (is_array($fetch_result)) {
            // Verify the array contains a valid id matching our request
            // This confirms fetch() actually returned data for this record
            // (not, for example, false cast to array or invalid data)
            if (!isset($fetch_result['id']) || $fetch_result['id'] != $id) {
                return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Record not found or access denied");
            }

            // Access granted - now fetch the actual Eloquent model for relationships
            $source_record = $model_class::find($id);
            if (!$source_record) {
                // Shouldn't happen if fetch() succeeded, but defensive check
                return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Record not found");
            }
        } else {
            // fetch() returned an Eloquent model directly - use it
            $source_record = $fetch_result;
        }

        // Call the relationship method
        $relation = $source_record->$relationship_name();

        // Determine if this is a singular or plural relationship
        $is_singular = $relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo
            || $relation instanceof \Illuminate\Database\Eloquent\Relations\HasOne
            || $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphOne
            || $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphTo;

        // Get just the IDs first (efficient single query)
        if ($is_singular) {
            // For singular relationships, get the foreign key value directly
            if ($relation instanceof \Illuminate\Database\Eloquent\Relations\MorphTo) {
                $related_id = $source_record->{$relation->getForeignKeyName()};
                $related_type = $source_record->{$relation->getMorphType()};
                if (!$related_id || !$related_type) {
                    return null;
                }
                // For morphTo, we need to resolve the related model class
                $related_class = $related_type;
            } else {
                $related_id = $relation->getQuery()->getQuery()->first()?->id ?? null;
                if (!$related_id) {
                    return null;
                }
                $related_class = get_class($relation->getRelated());
            }

            // Get simple class name for fetch
            $related_class_parts = explode('\\', $related_class);
            $related_model_name = end($related_class_parts);

            // Check if related model has fetch() with attribute
            try {
                $related_metadata = Manifest::php_get_metadata_by_class($related_model_name);
            } catch (\Exception $e) {
                return null;
            }

            $related_has_fetch = false;
            if (isset($related_metadata['public_static_methods'][$fetch_method_name])) {
                $fetch_method = $related_metadata['public_static_methods'][$fetch_method_name];
                if (isset($fetch_method['attributes']['Ajax_Endpoint_Model_Fetch'])) {
                    $related_has_fetch = true;
                }
            }

            if (!$related_has_fetch) {
                return null;
            }

            // The related record is served by ITS OWN (portal_)fetch() surface, which
            // is reached here by a direct static call rather than by re-entering this
            // endpoint - so that surface's gates are evaluated here. A denial reads as
            // "no related record", identical to an unfetchable relation above.
            if (!static::_fetch_gates_pass($related_model_name, $fetch_method_name)) {
                return null;
            }

            // Fetch through the security filter
            $related_fqcn = $related_metadata['fqcn'];
            return $related_fqcn::$fetch_method_name($related_id);
        } else {
            // For plural relationships, get the IDs efficiently - but never more than
            // the endpoint is willing to serve. Each id below costs a full fetch()
            // round trip, so an unbounded relation (a client with 80k contacts) would
            // turn one lazy `await record.things()` into 80k queries and a memory
            // fatal. One id past the cap is fetched purely to detect the overflow.
            $cap = (int) config('rsx.model_fetch.max_relationship_records');
            $related_ids = $relation->limit($cap + 1)->pluck('id')->toArray();

            if (count($related_ids) > $cap) {
                // Loud, not truncated: silently returning the first N would hand the
                // caller a partial set it has no way to detect.
                throw new \RuntimeException(
                    "Relationship '{$relationship_name}' on {$model_name} exceeds the "
                    . "{$cap}-record lazy-fetch cap (rsx.model_fetch.max_relationship_records). "
                    . 'A relation this size needs its own paginated #[Ajax_Endpoint], not a lazy fetch.'
                );
            }

            if (empty($related_ids)) {
                return [];
            }

            // Get the related model class
            $related_class = get_class($relation->getRelated());
            $related_class_parts = explode('\\', $related_class);
            $related_model_name = end($related_class_parts);

            // Check if related model has fetch() with attribute
            try {
                $related_metadata = Manifest::php_get_metadata_by_class($related_model_name);
            } catch (\Exception $e) {
                return [];
            }

            $related_has_fetch = false;
            if (isset($related_metadata['public_static_methods'][$fetch_method_name])) {
                $fetch_method = $related_metadata['public_static_methods'][$fetch_method_name];
                if (isset($fetch_method['attributes']['Ajax_Endpoint_Model_Fetch'])) {
                    $related_has_fetch = true;
                }
            }

            if (!$related_has_fetch) {
                return [];
            }

            // Same gate evaluation as the singular branch: the related model's own
            // fetch surface is invoked directly, so its gates are enforced here. A
            // denial yields an empty set, identical to an unfetchable relation.
            if (!static::_fetch_gates_pass($related_model_name, $fetch_method_name)) {
                return [];
            }

            // Fetch each record through its security filter. Every related id must still
            // go through its own (portal_)fetch() - that is the authorization boundary -
            // but the primary-key lookup each of those bodies performs is served from ONE
            // preloaded query instead of one query per id (see Orm_Fetch_Preload).
            $related_fqcn = $related_metadata['fqcn'];
            $results = [];

            try {
                Orm_Fetch_Preload::populate(
                    $related_fqcn,
                    $related_fqcn::whereIn('id', $related_ids)->get()
                );

                foreach ($related_ids as $related_id) {
                    $record = $related_fqcn::$fetch_method_name($related_id);
                    if ($record) {
                        $results[] = $record;
                    }
                }
            } finally {
                Orm_Fetch_Preload::clear();
            }

            return $results;
        }
    }

    /**
     * Evaluate the declarative #[Auth] gates on one model surface.
     *
     * The surface index carries the fully merged list (the model's class-level
     * #[Auth] followed by the member's own) for every #[Ajax_Endpoint_Model_Fetch]
     * member, so this is a straight lookup. fetch() is indexed staff, portal_fetch()
     * portal, and relationship methods realm-agnostic - the request's realm is the
     * right one for all three, and it is the same detection that chose the fetch
     * method name in the first place.
     *
     * The surface's declared realm is checked first, exactly as at the Ajax seam: a
     * realm-explicit member reached from the other realm is refused before any gate
     * name is resolved (Auth_Gates::surface_realm_permits).
     *
     * @param string $model_name Simple model class name
     * @param string $member fetch / portal_fetch / a relationship method
     * @return bool True when the realm permits and every gate passes
     */
    private static function _fetch_gates_pass(string $model_name, string $member): bool
    {
        $target = $model_name . '::' . $member;
        $realm = Auth_Gates::active_realm();

        if (!Auth_Gates::surface_realm_permits($target, $realm)) {
            return false;
        }

        $gates = Auth_Gates::surface_gates($target);

        if (empty($gates)) {
            return true;
        }

        return Auth_Gates::gates_pass_at_seam($gates, $realm, $target);
    }

    /**
     * Resolve which model fetch method this ORM request must call.
     *
     * Portal requests use portal_fetch() (the portal authorization contract, gated
     * on Portal_Session + portal_can_read()); all other requests use fetch() (the
     * staff authorization contract). There is exactly one ORM endpoint - the method
     * name is selected by request context, not by a separate controller.
     *
     * @return string 'portal_fetch' for portal requests, 'fetch' otherwise
     */
    private static function _resolve_fetch_method_name(): string
    {
        return \App\RSpade\Core\Portal\Rsx_Portal::is_portal_request() ? 'portal_fetch' : 'fetch';
    }
}
