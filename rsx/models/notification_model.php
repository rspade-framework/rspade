<?php

namespace Rsx\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;

/**
 * Notification_Model - User notifications with type-based rendering
 *
 * Stores per-user notifications with polymorphic entity references.
 * Each notification type has a renderer method that returns structured
 * data (text, URL, optional image) for flexible display.
 *
 * Self-policing: Notifications automatically validate their entity
 * during fetch operations, deleting invalid notifications.
 */


/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: notifications
 *
 * @property int $id
 * @property int $site_id
 * @property int $user_id
 * @property int $type_id
 * @property int $entity_type
 * @property int $entity_id
 * @property array $metadata
 * @property string $read_at
 * @property string $expires_at
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
class Notification_Model extends Rsx_Site_Model_Abstract
{
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per notification per recipient.
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
    const TYPE_CLIENT_CREATED = 1;
    const TYPE_CONTACT_CREATED = 10;
    const TYPE_PROJECT_CREATED = 20;
    const TYPE_TASK_ASSIGNED = 30;

    protected $table = 'notifications';
    protected $fillable = []; // No mass assignment - always explicit

    /**
     * Polymorphic type reference columns
     * entity_type stores integer ID in database but exposes class name string
     */
    protected static $type_ref_columns = ['entity_type'];

    public static $enums = [
        'type_id' => [
            // Client notifications (1-9)
            1 => [
                'constant' => 'TYPE_CLIENT_CREATED',
                'label' => 'Client Created',
                'renderer' => 'Rsx\\Lib\\Notification\\Notification_Renderer::client_created',
            ],
            // Contact notifications (10-19)
            10 => [
                'constant' => 'TYPE_CONTACT_CREATED',
                'label' => 'Contact Created',
                'renderer' => 'Rsx\\Lib\\Notification\\Notification_Renderer::contact_created',
            ],
            // Project notifications (20-29)
            20 => [
                'constant' => 'TYPE_PROJECT_CREATED',
                'label' => 'Project Created',
                'renderer' => 'Rsx\\Lib\\Notification\\Notification_Renderer::project_created',
            ],
            // Task notifications (30-39)
            30 => [
                'constant' => 'TYPE_TASK_ASSIGNED',
                'label' => 'Task Assigned',
                'renderer' => 'Rsx\\Lib\\Notification\\Notification_Renderer::task_assigned',
            ],
        ],
    ];

    /**
     * Render the notification using the renderer from enum
     *
     * @return array ['text' => string, 'url' => string|null, 'image_url' => string|null]
     */
    public function render(): array
    {
        $renderer = $this->type_id__renderer;
        return call_user_func($renderer, $this);
    }

    /**
     * The associated entity (polymorphic).
     *
     * Stock morphTo() over the entity_type/entity_id type-ref pair. Read it as a property
     * ($n->entity), not a method call.
     */
    #[Relationship]
    public function entity()
    {
        return $this->morphTo('entity', 'entity_type', 'entity_id');
    }

    /**
     * Check if this notification is still valid
     *
     * A notification is valid if its entity still exists (when entity is set).
     * Notifications without an entity reference are always valid.
     *
     * @return bool
     */
    public function is_valid(): bool
    {
        // No entity reference = always valid
        if (empty($this->entity_type) || empty($this->entity_id)) {
            return true;
        }

        // Check if entity exists
        return $this->entity !== null;
    }

    /**
     * Check if this notification has been read
     *
     * @return bool
     */
    public function is_read(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Mark this notification as read
     *
     * @return void
     */
    public function mark_read(): void
    {
        if ($this->read_at === null) {
            $this->read_at = now();
            $this->save();
        }
    }

    /**
     * Get the user who receives this notification
     */
    #[Relationship]
    public function user()
    {
        return $this->belongsTo(\Login_User_Model::class, 'user_id');
    }

    /**
     * The actor who created/triggered this notification, or null when the notification was
     * raised by the system with nobody signed in. Resolved through the framework audit
     * authorship pair (created_by_type / created_by_id), which save() stamps automatically -
     * so the returned model is whichever identity actually did it (User_Model,
     * Portal_User_Model or Login_User_Model), not a guess.
     */
    public function creator()
    {
        return $this->created_by;
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
     * Ajax model fetch - allows JavaScript to load notification records
     *
     * Self-polices: deletes invalid notifications and returns false
     */
    #[Ajax_Endpoint_Model_Fetch]
    public static function fetch($id)
    {
        $notification = static::find($id);

        if (!$notification) {
            return false;
        }

        // Self-police: delete if entity no longer valid
        if (!$notification->is_valid()) {
            $notification->delete();
            return false;
        }

        $data = $notification->toArray();

        // Add rendered notification data
        $data['render'] = $notification->render();
        $data['is_read'] = $notification->is_read();

        return $data;
    }
}