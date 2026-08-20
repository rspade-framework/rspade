<?php

namespace Rsx\Models;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use Rsx\Models\Action_Log_Model;

/**
 * Action_Log_Related_Model - Stores related entities for action log entries
 *
 * Each action log entry can have multiple related entities with different roles
 * (actor, target, mentioned). This enables querying "show me all actions
 * involving this entity" efficiently.
 *
 * @see /rsx/resource/man/action_log.txt for full documentation
 */


/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: action_log_related
 *
 * @property int $id
 * @property int $action_log_id
 * @property int $role_id
 * @property int $related_type
 * @property int $related_id
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property string $created_at
 * @property string $updated_at
 *
 * @property-read string $role_id__label
 * @property-read string $role_id__constant
 *
 * @method static array role_id__enum() Get all enum definitions with full metadata
 * @method static array role_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array role_id__enum_labels() Get simple id => label map
 * @method static array role_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
class Action_Log_Related_Model extends Rsx_Model_Abstract
{
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per entity touched per action log - a multiple of the log itself.
     *
     * A DECLARATION, not a runtime gate - a small, well-narrowed query here is still
     * fine. It marks the tables where a bare ->get() deserves a second look, and where
     * ->result_set() is usually the right answer. See the "Do The Whole Job" section
     * of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;

    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const ROLE_ACTOR = 1;
    const ROLE_TARGET = 2;
    const ROLE_MENTIONED = 3;

    protected $table = 'action_log_related';
    protected $fillable = []; // No mass assignment - always explicit

    /**
     * Polymorphic type reference columns
     * related_type stores integer ID in database but exposes class name string
     */
    protected static $type_ref_columns = ['related_type'];

    public static $enums = [
        'role_id' => [
            1 => [
                'constant' => 'ROLE_ACTOR',
                'label' => 'Actor',
            ],
            2 => [
                'constant' => 'ROLE_TARGET',
                'label' => 'Target',
            ],
            3 => [
                'constant' => 'ROLE_MENTIONED',
                'label' => 'Mentioned',
            ],
        ],
    ];

    /**
     * The related entity - the model that was related to this action.
     *
     * Stock morphTo() over the related_type/related_id type-ref pair. Read it as a
     * property ($row->related), not a method call.
     */
    #[Relationship]
    public function related()
    {
        return $this->morphTo('related', 'related_type', 'related_id');
    }

    /**
     * Relationships
     */
    #[Relationship]
    public function action_log()
    {
        return $this->belongsTo(Action_Log_Model::class, 'action_log_id');
    }
}