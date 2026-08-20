<?php

namespace Rsx\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use Rsx\Models\Action_Log_Related_Model;

/**
 * Action_Log_Model - Records user actions for activity history tracking
 *
 * This model stores individual action log entries with polymorphic actor (who did it)
 * and subject (what was acted upon) relationships. Each action type has a renderer
 * method that generates human-readable display text.
 *
 * @see /rsx/resource/man/action_log.txt for full documentation
 */


/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: action_logs
 *
 * @property int $id
 * @property int $site_id
 * @property int $type_id
 * @property int $actor_type
 * @property int $actor_id
 * @property int $subject_type
 * @property int $subject_id
 * @property array $metadata
 * @property int $created_by_id
 * @property int $created_by_type
 * @property string $created_at
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @property-read string $type_id__label
 * @property-read string $type_id__constant
 *
 * @method static array type_id__enum() Get all enum definitions with full metadata
 * @method static array type_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array type_id__enum_labels() Get simple id => label map
 * @method static array type_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
#[Auth('is_logged_in')]
class Action_Log_Model extends Rsx_Site_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const TYPE_CLIENT_CREATED = 1;
    const TYPE_CLIENT_UPDATED = 2;
    const TYPE_CLIENT_DELETED = 3;
    const TYPE_CONTACT_CREATED = 10;
    const TYPE_CONTACT_UPDATED = 11;
    const TYPE_CONTACT_DELETED = 12;
    const TYPE_PROJECT_CREATED = 20;
    const TYPE_PROJECT_UPDATED = 21;
    const TYPE_PROJECT_DELETED = 22;
    const TYPE_TASK_CREATED = 30;
    const TYPE_TASK_UPDATED = 31;
    const TYPE_TASK_DELETED = 32;
    const TYPE_PARTY_CREATED = 40;
    const TYPE_PARTY_UPDATED = 41;
    const TYPE_PARTY_DELETED = 42;
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per recorded user action - the archetypal append-only log.
     *
     * A DECLARATION, not a runtime gate - a small, well-narrowed query here is still
     * fine. It marks the tables where a bare ->get() deserves a second look, and where
     * ->result_set() is usually the right answer. See the "Do The Whole Job" section
     * of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;


    protected $table = 'action_logs';
    protected $fillable = []; // No mass assignment - always explicit

    /**
     * Polymorphic type reference columns
     * actor_type and subject_type store integer IDs in database but expose class name strings
     */
    protected static $type_ref_columns = ['actor_type', 'subject_type'];

    public static $enums = [
        'type_id' => [
            // Client actions (1-9)
            1 => [
                'constant' => 'TYPE_CLIENT_CREATED',
                'label' => 'Client Created',
                'renderer' => 'Rsx\\Lib\\Action_Log\\Action_Log_Renderer::client_created',
            ],
            2 => [
                'constant' => 'TYPE_CLIENT_UPDATED',
                'label' => 'Client Updated',
                'renderer' => 'Rsx\\Lib\\Action_Log\\Action_Log_Renderer::client_updated',
            ],
            3 => [
                'constant' => 'TYPE_CLIENT_DELETED',
                'label' => 'Client Deleted',
                'renderer' => 'Rsx\\Lib\\Action_Log\\Action_Log_Renderer::client_deleted',
            ],
            // Contact actions (10-19)
            10 => [
                'constant' => 'TYPE_CONTACT_CREATED',
                'label' => 'Contact Created',
                'renderer' => 'Rsx\\Lib\\Action_Log\\Action_Log_Renderer::contact_created',
            ],
            11 => [
                'constant' => 'TYPE_CONTACT_UPDATED',
                'label' => 'Contact Updated',
                'renderer' => 'Rsx\\Lib\\Action_Log\\Action_Log_Renderer::contact_updated',
            ],
            12 => [
                'constant' => 'TYPE_CONTACT_DELETED',
                'label' => 'Contact Deleted',
                'renderer' => 'Rsx\\Lib\\Action_Log\\Action_Log_Renderer::contact_deleted',
            ],
            // Project actions (20-29)
            20 => [
                'constant' => 'TYPE_PROJECT_CREATED',
                'label' => 'Project Created',
                'renderer' => 'Rsx\\Lib\\Action_Log\\Action_Log_Renderer::project_created',
            ],
            21 => [
                'constant' => 'TYPE_PROJECT_UPDATED',
                'label' => 'Project Updated',
                'renderer' => 'Rsx\\Lib\\Action_Log\\Action_Log_Renderer::project_updated',
            ],
            22 => [
                'constant' => 'TYPE_PROJECT_DELETED',
                'label' => 'Project Deleted',
                'renderer' => 'Rsx\\Lib\\Action_Log\\Action_Log_Renderer::project_deleted',
            ],
            // Task actions (30-39)
            30 => [
                'constant' => 'TYPE_TASK_CREATED',
                'label' => 'Task Created',
                'renderer' => 'Rsx\\Lib\\Action_Log\\Action_Log_Renderer::task_created',
            ],
            31 => [
                'constant' => 'TYPE_TASK_UPDATED',
                'label' => 'Task Updated',
                'renderer' => 'Rsx\\Lib\\Action_Log\\Action_Log_Renderer::task_updated',
            ],
            32 => [
                'constant' => 'TYPE_TASK_DELETED',
                'label' => 'Task Deleted',
                'renderer' => 'Rsx\\Lib\\Action_Log\\Action_Log_Renderer::task_deleted',
            ],
            // Party actions (40-49)
            40 => [
                'constant' => 'TYPE_PARTY_CREATED',
                'label' => 'Party Created',
                'renderer' => 'Rsx\\Lib\\Action_Log\\Action_Log_Renderer::party_created',
            ],
            41 => [
                'constant' => 'TYPE_PARTY_UPDATED',
                'label' => 'Party Updated',
                'renderer' => 'Rsx\\Lib\\Action_Log\\Action_Log_Renderer::party_updated',
            ],
            42 => [
                'constant' => 'TYPE_PARTY_DELETED',
                'label' => 'Party Deleted',
                'renderer' => 'Rsx\\Lib\\Action_Log\\Action_Log_Renderer::party_deleted',
            ],
        ],
    ];

    /**
     * Render the action log entry using the renderer from enum
     *
     * @return string HTML string with hyperlinks
     */
    public function render(): string
    {
        $renderer = $this->type_id__renderer;
        return call_user_func($renderer, $this);
    }

    /**
     * The actor (who performed the action) - Login_User_Model, or null for system actions.
     *
     * Stock morphTo() over the actor_type/actor_id type-ref pair. Read it as a property
     * ($log->actor), not a method call - the method returns the relation.
     */
    #[Relationship]
    public function actor()
    {
        return $this->morphTo('actor', 'actor_type', 'actor_id');
    }

    /**
     * The subject (what was acted upon) - the model that was created/updated/deleted.
     *
     * Stock morphTo() over the subject_type/subject_id type-ref pair. Read it as a
     * property ($log->subject), not a method call.
     */
    #[Relationship]
    public function subject()
    {
        return $this->morphTo('subject', 'subject_type', 'subject_id');
    }

    /**
     * Get display name for the actor
     *
     * @return string
     */
    public function actor_display(): string
    {
        $actor = $this->actor;
        return $actor ? $actor->email : 'System';
    }

    /**
     * Get display name for the subject
     *
     * @return string
     */
    public function subject_display(): string
    {
        $subject = $this->subject;
        if (!$subject) {
            return '(deleted)';
        }
        return $subject->name ?? $subject->title ?? "#{$subject->id}";
    }

    /**
     * Relationships
     */
    #[Relationship]
    public function related_entries()
    {
        return $this->hasMany(Action_Log_Related_Model::class, 'action_log_id');
    }

    /**
     * Get decoded metadata
     *
     * @return array
     */
    public function get_metadata(): array
    {
        if (empty($this->metadata)) {
            return [];
        }
        return is_array($this->metadata) ? $this->metadata : json_decode($this->metadata, true) ?? [];
    }

    /**
     * Ajax model fetch - allows JavaScript to load action log records
     */
    #[Ajax_Endpoint_Model_Fetch]
    public static function fetch($id)
    {
        $log = static::find($id);

        if (!$log) {
            return false;
        }

        $data = $log->toArray();

        // Add rendered action text and computed display values
        // Keys must match method names per PHP-ALIAS-01
        $data['render'] = $log->render();
        $data['actor_display'] = $log->actor_display();
        $data['subject_display'] = $log->subject_display();

        return $data;
    }
}