<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Portal\Portal_Authorizable;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Realtime\Realtime;
/**
 * Portal_Notification_Model - the framework-core portal activity / notification
 * primitive (T4). This is the bus that portal features emit into: the dashboard
 * "what's new" feed (AP-B), announcements (AP-C), portal requests (AP-D), and any
 * future portal feature. It resolves framework essentials #12 (activity/audit) and
 * #13 (notifications to portal users).
 *
 * Shape: ONE ROW PER RECIPIENT. A notification is addressed to a specific portal
 * user. An emitter that targets N users writes N rows. This is the natural shape
 * for a per-user feed and read-state at SMB scale.
 *
 * App-agnostic by design: there is NO hard FK to application tables. The portal
 * user is core (portal_users), so site_id + portal_user_id are first-class. An
 * app-specific subject (a client, a project, an announcement, a request) is
 * referenced POLYMORPHICALLY through the type-ref system - subject_type stores a
 * BIGINT type-ref id and reads back as the class basename, subject_id is the row
 * id. This keeps the primitive reusable and avoids an inverted core->app
 * dependency.
 *
 * Authorization: site-scoped (Rsx_Site_Model_Abstract) AND per-recipient. A portal
 * user may only ever read notifications addressed to them (portal_can_read()). The
 * query API always scopes to a recipient; never let a user read another user's
 * feed.
 *
 * @property int $id
 * @property int $site_id
 * @property int $portal_user_id
 * @property string $type
 * @property string $subject_type
 * @property int $subject_id
 * @property mixed $payload
 * @property string $read_at
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by
 * @property int $updated_by
 *
 * @mixin \Eloquent
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: portal_notifications
 *
 * @property int $id
 * @property int $site_id
 * @property int $portal_user_id
 * @property string $type
 * @property int $subject_type
 * @property int $subject_id
 * @property array $payload
 * @property string $read_at
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
#[Auth('is_logged_in')]
class Portal_Notification_Model extends Rsx_Site_Model_Abstract
            {
    use Portal_Authorizable;

    // No enums: `type` is a free-form string key owned by the emitting feature
    // (e.g. 'announcement', 'request_opened', 'request_revision'). Keeping it a
    // string keeps the primitive open - features add their own types without
    // editing this core model.
    public static $enums = [];
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per notification per portal user.
     *
     * Consumed by the DB-UNBOUNDED-01 code-quality rule, which flags a bare ->get() /
     * ->pluck() on this model in framework code and points at ->result_set(). It is a
     * DECLARATION, not a runtime gate - a small, well-narrowed query here is still fine.
     * See: the Do The Whole Job section of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;


    protected $table = 'portal_notifications';
    protected $fillable = []; // No mass assignment - always explicit

    /**
     * subject_type is a polymorphic type reference: it stores a BIGINT type-ref id
     * in the database and reads/writes as the class basename string transparently.
     */
    protected static $type_ref_columns = ['subject_type'];

    protected $casts = [
        'payload' => 'array',
    ];

    /**
     * The notification's polymorphic subject (a client, project, announcement, request...).
     *
     * Stock morphTo() over the subject_type/subject_id type-ref pair - the integer
     * discriminator is transparent to Eloquent (see Type_Ref_Registry::register_morph_map).
     * Read it as a property ($notification->subject); the method returns the relation.
     * Null when the notification carries no subject.
     */
    #[Relationship]
    public function subject()
    {
        return $this->morphTo('subject', 'subject_type', 'subject_id');
    }

    // =========================================================================
    // EMIT (the write side of the bus - features publish here)
    // =========================================================================

    /**
     * Record a notification for one or many recipient portal users.
     *
     * Writes one row per recipient and publishes a realtime event scoped to each
     * recipient so their browser can live-refresh. The row's own site_id is used
     * for both persistence and realtime scoping, so emit is correct from either a
     * staff request (e.g. an announcement broadcast) or a portal request.
     *
     * @param int|array $portal_user_ids One recipient id or a list of them.
     * @param string    $type            Type key (e.g. 'announcement').
     * @param array     $options         Optional:
     *                                     - subject_type (string class basename)
     *                                     - subject_id   (int)
     *                                     - payload      (array - title/body/url snapshot)
     *                                     - site_id      (int - defaults to the current site)
     * @return Portal_Notification_Model[] The created rows (one per recipient).
     */
    public static function emit($portal_user_ids, string $type, array $options = []): array
    {
        if (empty($type)) {
            throw new \InvalidArgumentException('Portal_Notification_Model::emit() requires a non-empty type');
        }

        // Normalize recipients to a unique list of positive ints.
        $recipients = is_array($portal_user_ids) ? $portal_user_ids : [$portal_user_ids];
        $recipients = array_values(array_unique(array_filter(array_map('intval', $recipients))));

        if (empty($recipients)) {
            throw new \InvalidArgumentException('Portal_Notification_Model::emit() requires at least one recipient');
        }

        $site_id = (int) ($options['site_id'] ?? static::get_current_site_id());
        $subject_type = $options['subject_type'] ?? null;
        $subject_id = isset($options['subject_id']) ? (int) $options['subject_id'] : null;
        $payload = $options['payload'] ?? null;

        $created = [];

        foreach ($recipients as $recipient_id) {
            $row = new static();
            $row->site_id = $site_id;
            $row->portal_user_id = $recipient_id;
            $row->type = $type;
            $row->subject_type = $subject_type; // null or class basename (type-ref cast)
            $row->subject_id = $subject_id;
            $row->payload = $payload;
            $row->read_at = null;
            $row->save();

            $created[] = $row;

            // Notification-only realtime: tell the recipient's browser something
            // arrived; it fetches the fresh feed through the Ajax endpoint. No
            // confidential data in the payload.
            Realtime::publish(
                'Portal_Notification_Topic',
                ['portal_user_id' => $recipient_id],
                $site_id
            );
        }

        return $created;
    }

    // =========================================================================
    // QUERY (the read side - the feed, scoped to a recipient)
    // =========================================================================

    /**
     * The recipient's feed, newest first. Always scoped to a single portal user.
     *
     * @param int   $portal_user_id Recipient.
     * @param array $options        Optional:
     *                                - limit       (int, default 50)
     *                                - unread_only (bool, default false)
     *                                - since       (ISO datetime - created_at > since;
     *                                               for "what's new since last login")
     * @return Portal_Notification_Model[]
     */
    public static function feed(int $portal_user_id, array $options = []): array
    {
        $limit = (int) ($options['limit'] ?? 50);
        $unread_only = (bool) ($options['unread_only'] ?? false);
        $since = $options['since'] ?? null;

        $query = static::where('portal_user_id', $portal_user_id)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        if ($unread_only) {
            $query->whereNull('read_at');
        }

        if (!empty($since)) {
            $query->where('created_at', '>', $since);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get()->all();
    }

    /**
     * Count of unread notifications for a recipient.
     *
     * @param int $portal_user_id
     * @return int
     */
    public static function unread_count(int $portal_user_id): int
    {
        return static::where('portal_user_id', $portal_user_id)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Mark a single notification read. Scoped to the recipient so a user can only
     * ever mark their own notifications. Idempotent; returns whether a row moved
     * from unread -> read.
     *
     * @param int $portal_user_id Recipient (ownership guard).
     * @param int $notification_id
     * @return bool
     */
    public static function mark_read(int $portal_user_id, int $notification_id): bool
    {
        $row = static::where('id', $notification_id)
            ->where('portal_user_id', $portal_user_id)
            ->first();

        if (!$row || $row->read_at !== null) {
            return false;
        }

        $row->read_at = now();
        $row->save();

        return true;
    }

    /**
     * Mark all of a recipient's unread notifications read.
     *
     * @param int $portal_user_id
     * @return int Number of rows updated.
     */
    public static function mark_all_read(int $portal_user_id): int
    {
        return static::where('portal_user_id', $portal_user_id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    // =========================================================================
    // PORTAL AUTHORIZATION (record-level)
    // =========================================================================

    /**
     * Record-level portal visibility (own-recipient rule).
     *
     * A portal user may only read notifications addressed to them. Fail-closed:
     * any mismatch denies. portal_fetch() (from Portal_Authorizable) has already
     * required an authenticated portal session before this is called. A core model
     * uses Portal_Session directly (no core->app dependency).
     *
     * @return bool
     */
    public function portal_can_read(): bool
    {
        return (int) $this->portal_user_id === (int) Portal_Session::get_portal_user_id();
    }
}
