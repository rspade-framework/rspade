<?php

namespace App\RSpade\Core\Api;

use App\RSpade\Core\Database\Models\Rsx_System_Model_Abstract;

/**
 * Api_Request_Log_Model - one row per external API request (_api_request_log).
 *
 * Every request that reaches Api_Dispatcher is recorded here: successes and every
 * failure path (401/404/405/422/400/500). Rows carry whatever identity is known
 * (api_key_id/user_id/site_id are nullable - a failed auth has none) plus the verb,
 * path, resolved handler, HTTP status, wall-clock duration, and client IP.
 *
 * Infrastructure table: writes are observability, never user-facing data anyone
 * subscribes to, so realtime emission is suppressed. Pruned by Api_Cleanup_Service
 * per config('rsx.api.log_retention_days').
 *
 * @property int $id
 * @property int|null $api_key_id
 * @property int|null $user_id
 * @property int|null $site_id
 * @property string $verb
 * @property string $path
 * @property string|null $handler
 * @property int $status
 * @property int $duration_ms
 * @property string|null $ip
 * @property string $created_at
 * @property string $updated_at
 *
 * @mixin \Eloquent
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _api_request_log
 *
 * @property int $id
 * @property int $api_key_id
 * @property int $user_id
 * @property int $site_id
 * @property string $verb
 * @property string $path
 * @property string $handler
 * @property int $status
 * @property int $duration_ms
 * @property string $ip
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
class Api_Request_Log_Model extends Rsx_System_Model_Abstract
{
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per API request - the fastest-growing table in the framework.
     *
     * Consumed by the DB-UNBOUNDED-01 code-quality rule, which flags a bare ->get() /
     * ->pluck() on this model in framework code and points at ->result_set(). It is a
     * DECLARATION, not a runtime gate - a small, well-narrowed query here is still fine.
     * See: the Do The Whole Job section of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;

    protected $table = '_api_request_log';

    public static $enums = [];

    /**
     * Per-request infrastructure churn - never kicks the realtime emitter engine.
     * @var bool
     */
    public static $realtime_silent = true;
}
