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
use Rsx\Models\Contact_Model;
use Rsx\Models\Portal_Request_Document_Model;
use Rsx\Models\Portal_Request_Thread_Model;

/**
 * Portal_Request_Message_Model - one append-only post in a request thread. The author is
 * polymorphic: a Login_User_Model (staff) or a Portal_User_Model (portal user), stored in
 * the author_type type-ref BIGINT column (class basename, mapped to/from an int by the
 * framework) + author_id. Messages are never edited or deleted (audit trail).
 *
 * @property int $id
 * @property int $site_id
 * @property int $thread_id
 * @property string|null $author_type
 * @property int|null $author_id
 * @property string|null $body
 * @property string $created_at
 * @property string $updated_at
 *
 * @mixin \Eloquent
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: portal_request_messages
 *
 * @property int $id
 * @property int $site_id
 * @property int $thread_id
 * @property int $author_type
 * @property int $author_id
 * @property string $body
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
class Portal_Request_Message_Model extends Rsx_Site_Model_Abstract
           {
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * A message stream: rows per thread, unbounded over the thread's life.
     *
     * A DECLARATION, not a runtime gate - a small, well-narrowed query here is still
     * fine. It marks the tables where a bare ->get() deserves a second look, and where
     * ->result_set() is usually the right answer. See the "Do The Whole Job" section
     * of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;

    protected $table = 'portal_request_messages';
    protected $fillable = [];
    public static $enums = []; // no enum columns (author is a type-ref, body is free text)

    // Realtime: a message emits its own change AND (via #[Realtime_Touch] on thread())
    // touches its thread, which in turn touches its client - the bottom link of the
    // 2-level cross-surface touch chain: portal reply -> message -> thread -> client ->
    // staff Clients_View live-refresh (onward cascade hydrates the thread to walk it).
    public static $realtime = true;

    // author_type stores a class basename as a polymorphic type reference.
    protected static $type_ref_columns = ['author_type'];

    /**
     * Parent thread (the message timeline depends on it; the thread then touches its client).
     * #[Realtime_Touch] drives the parent-emission cascade. Plain FK belongsTo.
     */
    #[Relationship]
    #[Realtime_Touch]
    public function thread()
    {
        return $this->belongsTo(Portal_Request_Thread_Model::class, 'thread_id');
    }

    public function is_from_staff(): bool
    {
        return (string) $this->author_type === 'Login_User_Model';
    }

    /** Resolve the author model (staff or portal) or null. */
    public function author()
    {
        if (!$this->author_id) {
            return null;
        }
        return $this->is_from_staff()
            ? Login_User_Model::find($this->author_id)
            : Portal_User_Model::find($this->author_id);
    }

    /** Display name of the author ("Jane Doe" staff, or the portal user's name/email). */
    public function author_name(): string
    {
        $author = $this->author();
        if (!$author) {
            return 'Unknown';
        }
        if ($this->is_from_staff()) {
            $name = trim((string) ($author->name ?? ''));
            return $name !== '' ? $name : (string) $author->email;
        }
        // Portal user: prefer a linked contact's full name, else the email.
        if (!empty($author->contact_id)) {
            $contact = Contact_Model::find($author->contact_id);
            if ($contact) {
                $full = trim($contact->full_name());
                if ($full !== '') {
                    return $full;
                }
            }
        }
        return (string) $author->email;
    }

    public function author_email(): string
    {
        $author = $this->author();
        return $author ? (string) $author->email : '';
    }

    /** Review documents attached to this message. @return Portal_Request_Document_Model[] */
    public function documents(): array
    {
        return Portal_Request_Document_Model::where('message_id', $this->id)
            ->orderBy('id')->get()->all();
    }
}
