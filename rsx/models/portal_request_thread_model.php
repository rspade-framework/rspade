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
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Authorizable;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Portal_Membership_Model;
use Rsx\Models\Portal_Request_Document_Model;
use Rsx\Models\Portal_Request_Event_Model;
use Rsx\Models\Portal_Request_Message_Model;
use Rsx\Portal_Permission;
/**
 * Portal_Request_Thread_Model - a stateful firm <-> client conversation ("Request") for
 * collecting documents, questionnaires, checklists, etc. The thread carries a single
 * status LIFECYCLE; messages, status events and document-review state live in sibling
 * tables:
 *   - Portal_Request_Message_Model  (append-only timeline posts; polymorphic author)
 *   - Portal_Request_Event_Model    (status-change cards)
 *   - Portal_Request_Document_Model (per-attachment review state)
 *
 * CLIENT-LEVEL audience: every member of the thread's client sees and (collaborators)
 * participate. Portal read gate = membership (Portal_Authorizable -> portal_can_read).
 *
 * Status (see $enums). Only RESPONSE_SUBMITTED differs by audience - staff see it as
 * "Needs Review" via status_label(false). CLOSED is the only "closed" group; everything
 * else (incl. APPROVED) is "active". A portal member posting on a non-closed thread auto
 * -transitions it to RESPONSE_SUBMITTED; staff set NEEDS_MORE_INFO / APPROVED / CLOSED.
 *
 * @property int $id
 * @property int $site_id
 * @property int $client_id
 * @property string $title
 * @property int $status_id
 * @property int|null $created_by
 * @property string|null $last_activity_at
 * @property string $created_at
 * @property string $updated_at
 *
 * @mixin \Eloquent
 */


/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: portal_request_threads
 *
 * @property int $id
 * @property int $site_id
 * @property int $client_id
 * @property string $title
 * @property int $status_id
 * @property int $created_by_id
 * @property int $created_by_type
 * @property string $last_activity_at
 * @property string $created_at
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @property-read string $status_id__label
 * @property-read string $status_id__constant
 *
 * @method static array status_id__enum() Get all enum definitions with full metadata
 * @method static array status_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array status_id__enum_labels() Get simple id => label map
 * @method static array status_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
/**
 * AUTHORIZATION: ONE class gate, 'is_logged_in', covering BOTH fetch entry points -
 * the staff fetch() (resolved against Permission) and the trait-supplied portal_fetch()
 * (resolved against Portal_Permission). Each surface is realm-indexed, so the same name
 * means "a session of THAT realm exists". Record-level narrowing stays where it is: the
 * site global scope on the staff side, portal_can_read() on the portal side.
 */
#[Auth('is_logged_in')]
class Portal_Request_Thread_Model extends Rsx_Site_Model_Abstract
{
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per request a client opens.
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
    const STATUS_NEW_REQUEST = 1;
    const STATUS_NEEDS_MORE_INFO = 2;
    const STATUS_RESPONSE_SUBMITTED = 3;
    const STATUS_APPROVED = 4;
    const STATUS_CLOSED = 5;

    use Portal_Authorizable;

    protected $table = 'portal_request_threads';
    protected $fillable = [];

    // Realtime: a thread emits its own change AND (via #[Realtime_Touch] on client())
    // touches its client, so a portal reply (message -> thread -> client) live-refreshes
    // the staff Clients_View. This is the top link of the 2-level cross-surface touch chain.
    public static $realtime = true;

    /**
     * Parent client (the client's request-thread result set depends on this thread).
     * #[Realtime_Touch] drives the parent-emission cascade. Plain FK belongsTo.
     */
    #[Relationship]
    #[Realtime_Touch]
    public function client()
    {
        return $this->belongsTo(Client_Model::class, 'client_id');
    }

    // Notification type keys this feature emits under.
    const NOTIFICATION_TYPE_REPLY = 'request_thread_reply';     // firm -> client: new staff reply
    const NOTIFICATION_TYPE_STATUS = 'request_thread_status';   // firm -> client: status changed
    const NOTIFICATION_TYPE_DECISION = 'request_thread_decision'; // firm -> client: a document was accepted/rejected

    public static $enums = [
        'status_id' => [
            1 => ['constant' => 'STATUS_NEW_REQUEST', 'label' => 'New Request', 'badge' => 'bg-secondary', 'order' => 1],
            2 => ['constant' => 'STATUS_NEEDS_MORE_INFO', 'label' => 'Needs More Info', 'badge' => 'bg-warning text-dark', 'order' => 2],
            // staff_label: staff see RESPONSE_SUBMITTED as "Needs Review" (the action they must take).
            3 => ['constant' => 'STATUS_RESPONSE_SUBMITTED', 'label' => 'Response Submitted', 'staff_label' => 'Needs Review', 'badge' => 'bg-info text-dark', 'order' => 3],
            4 => ['constant' => 'STATUS_APPROVED', 'label' => 'Approved', 'badge' => 'bg-success', 'order' => 4],
            5 => ['constant' => 'STATUS_CLOSED', 'label' => 'Closed', 'badge' => 'bg-dark', 'order' => 5],
        ],
    ];

    /**
     * Audience-aware status label. Portal users always see the canonical label; staff see
     * the staff_label override where one exists (only RESPONSE_SUBMITTED -> "Needs Review").
     */
    public function status_label(bool $is_portal): string
    {
        $enum = static::$enums['status_id'][$this->status_id] ?? null;
        if (!$enum) {
            return 'Unknown';
        }
        if (!$is_portal && !empty($enum['staff_label'])) {
            return $enum['staff_label'];
        }
        return $enum['label'];
    }

    public function is_closed(): bool
    {
        return (int) $this->status_id === static::STATUS_CLOSED;
    }

    /**
     * Portal record-level read gate: a portal user may read this thread iff they are a
     * member of its client. Fail-closed.
     */
    public function portal_can_read(): bool
    {
        return Portal_Permission::has_client_access((int) $this->client_id);
    }

    /**
     * Recipient portal user ids for client-level notifications: active members of the
     * thread's client.
     *
     * @return int[]
     */
    public function resolve_recipient_ids(): array
    {
        $portal_user_ids = Portal_Membership_Model::get_for_client((int) $this->client_id)
            ->pluck('portal_user_id')->unique()->all();
        if (empty($portal_user_ids)) {
            return [];
        }
        $active_ids = Portal_User_Model::whereIn('id', $portal_user_ids)
            ->where('status_id', Portal_User_Model::STATUS_ACTIVE)
            ->pluck('id')->all();

        return array_values(array_map('intval', $active_ids));
    }

    /**
     * Sidebar participants, in two groups (no duplicates within a group):
     *   - members: EVERY portal member of the thread's client (invited / allowed to view),
     *     regardless of whether they have posted. Display name + phone resolved from the
     *     portal user's linked contact when present, else the email's local part / null.
     *   - staff:   ONLY staff (Login_User_Model authors) who have POSTED in this thread -
     *     staff are unrestricted viewers and are never explicitly added. The site staff
     *     profile (User_Model on the thread's site) supplies the display name, phone and
     *     profile-photo ATTACHMENT ID; falls back to the login email / initials.
     *
     * A participant never carries an image URL: avatar_attachment_id names the
     * File_Attachment, and <Person_Avatar> hands it to <Attachment_Thumbnail>, which owns
     * the URL and keeps the picture correct as the record changes.
     *
     * Each entry: {type, id, name, email, phone (nullable), avatar_attachment_id (nullable), initials}.
     *
     * @return array{members: array<int, array>, staff: array<int, array>}
     */
    public function participants(): array
    {
        // Members: all portal members of the client (regardless of posting).
        $members = [];
        $seen_member_ids = [];
        foreach (Portal_Membership_Model::get_for_client((int) $this->client_id) as $membership) {
            $portal_user_id = (int) $membership->portal_user_id;
            if (isset($seen_member_ids[$portal_user_id])) {
                continue;
            }
            $portal_user = Portal_User_Model::find($portal_user_id);
            if (!$portal_user) {
                continue;
            }
            $seen_member_ids[$portal_user_id] = true;

            // Resolve a coherent contact card from the member's linked contact (name +
            // email + phone all from one person), falling back to the portal login email.
            $name = null;
            $phone = null;
            $email = (string) $portal_user->email;
            if (!empty($portal_user->contact_id)) {
                $contact = Contact_Model::find($portal_user->contact_id);
                if ($contact) {
                    $full = trim($contact->full_name());
                    if ($full !== '') {
                        $name = $full;
                    }
                    if (trim((string) $contact->email) !== '') {
                        $email = (string) $contact->email;
                    }
                    $phone = $contact->phone_cell ?: ($contact->phone_work ?: null);
                }
            }
            if ($name === null) {
                $at = strpos($email, '@');
                $name = $at !== false ? substr($email, 0, $at) : $email;
            }

            $members[] = [
                'type' => 'portal',
                'id' => $portal_user_id,
                'name' => $name,
                'email' => $email,
                'phone' => $phone ?: null,
                'avatar_attachment_id' => null,
                'initials' => static::_participant_initials($name),
            ];
        }

        // Staff: distinct Login_User_Model authors of messages in this thread.
        $staff = [];
        $seen_staff_ids = [];
        foreach ($this->messages() as $message) {
            if (!$message->is_from_staff() || !$message->author_id) {
                continue;
            }
            $login_user_id = (int) $message->author_id;
            if (isset($seen_staff_ids[$login_user_id])) {
                continue;
            }
            $login_user = Login_User_Model::find($login_user_id);
            if (!$login_user) {
                continue;
            }
            $seen_staff_ids[$login_user_id] = true;

            // Resolve the site staff profile (User_Model for this login on the thread's site).
            $user = User_Model::where('login_user_id', $login_user_id)
                ->where('site_id', (int) $this->site_id)
                ->first();

            $name = $user ? $user->get_printed_name() : (string) $login_user->email;
            $phone = ($user && trim((string) $user->phone) !== '') ? $user->phone : null;

            // Staff profile photo: a 'profile_photo' File_Attachment on the User_Model.
            $avatar_attachment_id = null;
            if ($user) {
                $photo = $user->get_attachment('profile_photo');
                if ($photo) {
                    $avatar_attachment_id = (int) $photo->id;
                }
            }

            $staff[] = [
                'type' => 'staff',
                'id' => $login_user_id,
                'name' => $name,
                'email' => (string) $login_user->email,
                'phone' => $phone,
                'avatar_attachment_id' => $avatar_attachment_id,
                'initials' => static::_participant_initials($name),
            ];
        }

        return [
            'members' => $members,
            'staff' => $staff,
        ];
    }

    /**
     * Two-letter initials for a participant: first-name + last-name initial when the name
     * looks like a real "First Last", else the first two letters of the name. Uppercased.
     */
    private static function _participant_initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/', $name);
        if (count($parts) >= 2 && $parts[0] !== '' && $parts[count($parts) - 1] !== '') {
            return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
        }
        return strtoupper(substr($name, 0, 2));
    }

    // ---- timeline -------------------------------------------------------------------

    /** Messages in this thread, oldest first. @return Portal_Request_Message_Model[] */
    public function messages(): array
    {
        return Portal_Request_Message_Model::where('thread_id', $this->id)
            ->orderBy('created_at')->orderBy('id')->get()->all();
    }

    /** Status-change events in this thread, oldest first. @return Portal_Request_Event_Model[] */
    public function events(): array
    {
        return Portal_Request_Event_Model::where('thread_id', $this->id)
            ->orderBy('created_at')->orderBy('id')->get()->all();
    }

    /** Review documents in this thread. @return Portal_Request_Document_Model[] */
    public function documents(): array
    {
        return Portal_Request_Document_Model::where('thread_id', $this->id)
            ->orderBy('created_at')->orderBy('id')->get()->all();
    }

    // ---- mutations ------------------------------------------------------------------

    /**
     * Change the thread status, stamping last_activity_at and recording a
     * Portal_Request_Event (the timeline card). No-op if the status is unchanged.
     * $actor is a Login_User_Model (staff) or Portal_User_Model (portal) or null.
     */
    public function set_status(int $new_status, $actor = null): void
    {
        $old = (int) $this->status_id;
        if ($old === $new_status) {
            return;
        }

        $event = new Portal_Request_Event_Model();
        $event->site_id = (int) $this->site_id;
        $event->thread_id = (int) $this->id;
        $event->from_status = $old;
        $event->to_status = $new_status;
        if ($actor) {
            $event->actor_type = class_basename($actor);
            $event->actor_id = (int) $actor->id;
        }
        $event->save();

        $this->status_id = $new_status;
        $this->last_activity_at = now()->toDateTimeString();
        $this->save();
    }

    /**
     * Append a message authored by $author (Login_User_Model staff or Portal_User_Model).
     * Bumps last_activity_at. A PORTAL author on a non-closed thread auto-transitions the
     * status to RESPONSE_SUBMITTED. Returns the new message.
     */
    public function post_message($author, ?string $body): Portal_Request_Message_Model
    {
        $message = new Portal_Request_Message_Model();
        $message->site_id = (int) $this->site_id;
        $message->thread_id = (int) $this->id;
        $message->author_type = class_basename($author);
        $message->author_id = (int) $author->id;
        $message->body = $body !== '' ? $body : null;
        $message->save();

        $this->last_activity_at = now()->toDateTimeString();
        $this->save();

        // Portal reply on a non-closed thread -> Needs Review (Response Submitted).
        if ($author instanceof Portal_User_Model && !$this->is_closed()) {
            $this->set_status(static::STATUS_RESPONSE_SUBMITTED, $author);
        }

        return $message;
    }

    /**
     * Threads for a client, newest activity first, for the staff/portal list. Site scope
     * is automatic.
     *
     * @return Portal_Request_Thread_Model[]
     */
    public static function for_client(int $client_id): array
    {
        return static::where('client_id', $client_id)
            ->orderByRaw('COALESCE(last_activity_at, created_at) DESC')
            ->orderBy('id', 'desc')
            ->get()->all();
    }

    /**
     * Staff Ajax model fetch. Gated by the class-level #[Auth('is_logged_in')] (staff
     * realm here); the site global scope restricts to this site. The portal-realm entry
     * point is portal_fetch() (Portal_Authorizable -> portal_can_read), covered by the
     * same class gate in the portal realm.
     */
    #[Ajax_Endpoint_Model_Fetch]
    public static function fetch($id)
    {
        $thread = static::find($id);
        return $thread ? $thread->toArray() : false;
    }
}