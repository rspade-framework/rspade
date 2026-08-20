<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Models\Portal_Notification_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use Rsx\Models\Portal_Membership_Model;

/**
 * Announcement_Model (AP-C) - an APPLICATION feature, not framework core. Staff
 * post an announcement targeting one client's portal members (or, when client_id
 * is null, every active portal member on the site). Publishing emits ONE
 * Portal_Notification (the T4 core primitive) per targeted portal user, which is
 * what makes the announcement appear in each recipient's activity feed (the T5
 * dashboard) - proving the notification bus end to end.
 *
 * This model owns the announcement content and audience resolution; it does NOT
 * build a parallel notification mechanism - it emits into Portal_Notification_Model.
 *
 * Site-scoped (Rsx_Site_Model_Abstract auto-stamps + scopes by site_id). Composed
 * and published by STAFF in the internal app, so fetch() uses the staff session;
 * there is no portal_fetch() because portal users never read this row directly -
 * they receive the emitted notification snapshot.
 *
 * @property int $id
 * @property int $site_id
 * @property int $client_id
 * @property string $title
 * @property string $body
 * @property string $published_at
 * @property int $created_by
 * @property int $updated_by
 * @property string $created_at
 * @property string $updated_at
 *
 * @mixin \Eloquent
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: announcements
 *
 * @property int $id
 * @property int $site_id
 * @property int $client_id
 * @property string $title
 * @property string $body
 * @property string $published_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property string $created_at
 * @property string $updated_at
 *
 * @mixin \Eloquent
 */
#[Auth('is_logged_in')]
class Announcement_Model extends Rsx_Site_Model_Abstract
            {
    // The notification `type` key this feature emits under (free-form string owned
    // by the emitter, per Portal_Notification_Model's design).
    const NOTIFICATION_TYPE = 'announcement';

    protected $table = 'announcements';
    protected $fillable = []; // No mass assignment - always explicit

    public static $enums = [];

    /**
     * Is this announcement published (broadcast) yet? An unpublished row is a draft.
     */
    public function is_published(): bool
    {
        return !empty($this->published_at);
    }

    /**
     * Resolve the recipient portal user ids for this announcement's audience.
     *
     * Audience (basic version):
     *   - client_id set  -> all active portal members of that client.
     *   - client_id null -> every active portal member on the site (site-wide).
     *
     * Returns a de-duplicated list of Portal_User_Model ids. Only ACTIVE portal
     * accounts are targeted (a suspended/inactive user gets nothing).
     *
     * @return int[]
     */
    public function resolve_recipient_ids(): array
    {
        if ($this->client_id) {
            $memberships = Portal_Membership_Model::get_for_client((int) $this->client_id);
        } else {
            // Site-wide: every membership on the site (the global scope already
            // restricts to this site_id).
            $memberships = Portal_Membership_Model::query()->get();
        }

        $portal_user_ids = $memberships->pluck('portal_user_id')->unique()->all();
        if (empty($portal_user_ids)) {
            return [];
        }

        // Keep only active portal accounts.
        $active_ids = Portal_User_Model::whereIn('id', $portal_user_ids)
            ->where('status_id', Portal_User_Model::STATUS_ACTIVE)
            ->pluck('id')
            ->all();

        return array_values(array_map('intval', $active_ids));
    }

    /**
     * Publish this announcement: stamp published_at and emit one portal notification
     * per targeted portal user. Idempotent on the broadcast - re-publishing an
     * already-published announcement does NOT re-emit (avoids duplicate feed items).
     *
     * The emitted payload is a snapshot (title/body/url) so the recipient's feed is
     * self-contained and never has to read the announcement row.
     *
     * @return int Number of notifications emitted (recipients reached).
     */
    public function publish(): int
    {
        if ($this->is_published()) {
            return 0;
        }

        $this->published_at = now();
        $this->save();

        $recipient_ids = $this->resolve_recipient_ids();
        if (empty($recipient_ids)) {
            return 0;
        }

        Portal_Notification_Model::emit($recipient_ids, self::NOTIFICATION_TYPE, [
            'site_id' => (int) $this->site_id,
            'subject_type' => 'Announcement_Model',
            'subject_id' => (int) $this->id,
            'payload' => [
                'title' => $this->title,
                'body' => $this->body,
                // No url: announcements are read in-feed (no standalone portal page
                // in the basic version). A future portal announcements page would
                // set a url here.
            ],
        ]);

        return count($recipient_ids);
    }

    /**
     * Recent announcements for a client (newest first), for the staff compose UI's
     * "recent announcements" list. Includes drafts. Site scope is automatic.
     *
     * @return Announcement_Model[]
     */
    public static function recent_for_client(int $client_id, int $limit = 20): array
    {
        return static::where('client_id', $client_id)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Staff Ajax model fetch - the internal app loads an announcement for the
     * compose/view UI. Staff-only; pre_dispatch on the calling controller already
     * enforces the staff session, and the site global scope restricts to this site.
     */
    #[Ajax_Endpoint_Model_Fetch]
    public static function fetch($id)
    {
        $announcement = static::find($id);
        if (!$announcement) {
            return false;
        }

        $data = $announcement->toArray();
        $data['is_published'] = $announcement->is_published();

        return $data;
    }
}
