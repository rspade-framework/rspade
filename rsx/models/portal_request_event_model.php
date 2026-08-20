<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use Rsx\Models\Portal_Request_Thread_Model;

/**
 * Portal_Request_Event_Model - a status-change event in a request thread, rendered as an
 * alert-style card interleaved with messages in the timeline (ordered by created_at).
 * from_status/to_status are Portal_Request_Thread_Model status ids; actor is polymorphic
 * (Login_User_Model staff | Portal_User_Model) via the actor_type type-ref column.
 *
 * @property int $id
 * @property int $site_id
 * @property int $thread_id
 * @property int|null $from_status
 * @property int $to_status
 * @property string|null $actor_type
 * @property int|null $actor_id
 * @property string $created_at
 * @property string $updated_at
 *
 * @mixin \Eloquent
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: portal_request_events
 *
 * @property int $id
 * @property int $site_id
 * @property int $thread_id
 * @property int $from_status
 * @property int $to_status
 * @property int $actor_type
 * @property int $actor_id
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
class Portal_Request_Event_Model extends Rsx_Site_Model_Abstract
           {
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * An event stream on every thread - the fastest-growing of the request tables.
     *
     * A DECLARATION, not a runtime gate - a small, well-narrowed query here is still
     * fine. It marks the tables where a bare ->get() deserves a second look, and where
     * ->result_set() is usually the right answer. See the "Do The Whole Job" section
     * of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;

    protected $table = 'portal_request_events';
    protected $fillable = [];
    public static $enums = []; // no enum columns (from/to_status reference the thread enum; actor is a type-ref)

    protected static $type_ref_columns = ['actor_type'];

    /** Audience-aware label for the status this event moved TO. */
    public function to_status_label(bool $is_portal): string
    {
        return $this->_status_label((int) $this->to_status, $is_portal);
    }

    /** Audience-aware label for the status this event moved FROM (empty if none). */
    public function from_status_label(bool $is_portal): string
    {
        return $this->from_status ? $this->_status_label((int) $this->from_status, $is_portal) : '';
    }

    private function _status_label(int $status_id, bool $is_portal): string
    {
        $enum = Portal_Request_Thread_Model::$enums['status_id'][$status_id] ?? null;
        if (!$enum) {
            return 'Unknown';
        }
        if (!$is_portal && !empty($enum['staff_label'])) {
            return $enum['staff_label'];
        }
        return $enum['label'];
    }

    /** Display name of the actor who triggered the change (or 'System'). */
    public function actor_name(): string
    {
        if (!$this->actor_id) {
            return 'System';
        }
        $is_staff = (string) $this->actor_type === 'Login_User_Model';
        $actor = $is_staff
            ? Login_User_Model::find($this->actor_id)
            : Portal_User_Model::find($this->actor_id);
        if (!$actor) {
            return 'Unknown';
        }
        $name = trim((string) ($actor->name ?? ''));
        return $name !== '' ? $name : (string) $actor->email;
    }
}
