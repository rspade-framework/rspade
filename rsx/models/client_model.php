<?php

namespace Rsx\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Models\Country_Model;
use App\RSpade\Core\Models\Region_Model;
use App\RSpade\Core\Models\User_Model;
use Rsx\Models\Client_Department_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Portal_Membership_Model;
use Rsx\Models\Shared_Item_Model;
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: clients
 *
 * @property int $id
 * @property int $site_id
 * @property string $name
 * @property string $address
 * @property string $city
 * @property string $state
 * @property string $zip
 * @property string $phone
 * @property string $fax
 * @property string $phone_secondary
 * @property string $website
 * @property string $email
 * @property int $billing_contact_id
 * @property int $priority
 * @property string $notes
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $owner_user_id
 * @property string $created_at
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property string $address_street
 * @property string $address_country
 * @property string $industry
 * @property string $company_size
 * @property int $established_year
 * @property string $revenue_range
 * @property string $facebook_url
 * @property string $twitter_handle
 * @property string $linkedin_url
 * @property string $instagram_handle
 * @property array $tags
 * @property int $status_id
 * @property string $preferred_contact_method
 * @property int $newsletter_opt_in
 * @property int $portal_enabled
 * @property string $portal_last_activity_at
 * @property string $deleted_at
 * @property int $deleted_by_id
 * @property int $deleted_by_type
 *
 * @property-read string $priority__label
 * @property-read string $priority__constant
 * @property-read string $status_id__label
 * @property-read string $status_id__constant
 *
 * @method static array priority__enum() Get all enum definitions with full metadata
 * @method static array priority__enum_select() Get [{value, label}] array for dropdowns
 * @method static array priority__enum_labels() Get simple id => label map
 * @method static array priority__enum_ids() Get array of all valid enum IDs
 * @method static array status_id__enum() Get all enum definitions with full metadata
 * @method static array status_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array status_id__enum_labels() Get simple id => label map
 * @method static array status_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
#[Auth('is_logged_in')]
class Client_Model extends Rsx_Site_Model_Abstract
{
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * Grows with the business; "every client on this site" is a real query shape.
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
    const PRIORITY_LOW = 1;
    const PRIORITY_MEDIUM = 2;
    const PRIORITY_HIGH = 3;
    const PRIORITY_URGENT = 4;
    const STATUS_ACTIVE = 1;
    const STATUS_INACTIVE = 2;
    const STATUS_PROSPECT = 3;
    const STATUS_ARCHIVED = 4;

    /**
     * The attachment category a client's uploaded documents live under.
     *
     * ON THE MODEL, not on a controller, because more than one surface claims files into
     * it: the staff Documents tab (Frontend_Clients_Controller) and the external REST API
     * (Clients_Api_Controller) both attach here, and they must name the SAME string or the
     * API would quietly write into a category the UI never displays.
     */
    const DOCUMENTS_CATEGORY = 'documents';

    use SoftDeletes;

    protected $table = 'clients';
    protected $fillable = []; // No mass assignment - always explicit

    // Realtime: this model publishes a Model_Changed_Topic notification on save/delete
    // (flushed on commit) so subscribers (e.g. Clients_View) live-refresh.
    public static $realtime = true;

    public static $enums = [
        'priority' => [
            1 => ['constant' => 'PRIORITY_LOW', 'label' => 'Low', 'badge' => 'bg-secondary'],
            2 => ['constant' => 'PRIORITY_MEDIUM', 'label' => 'Medium', 'badge' => 'bg-primary'],
            3 => ['constant' => 'PRIORITY_HIGH', 'label' => 'High', 'badge' => 'bg-warning'],
            4 => ['constant' => 'PRIORITY_URGENT', 'label' => 'Urgent', 'badge' => 'bg-danger'],
        ],
        'status_id' => [
            1 => ['constant' => 'STATUS_ACTIVE', 'label' => 'Active', 'badge' => 'bg-success'],
            2 => ['constant' => 'STATUS_INACTIVE', 'label' => 'Inactive', 'badge' => 'bg-secondary'],
            3 => ['constant' => 'STATUS_PROSPECT', 'label' => 'Prospect', 'badge' => 'bg-info'],
            4 => ['constant' => 'STATUS_ARCHIVED', 'label' => 'Archived', 'badge' => 'bg-warning'],
        ],
    ];

    // Enum constants (auto-generated by rsx:constants:regenerate)

    /**
     * Get human-readable country name from country code
     * @return string|null
     */
    public function country_name()
    {
        if (!$this->address_country) {
            return null;
        }

        $country = Country_Model::where('alpha2', $this->address_country)->first();
        return $country ? $country->name : $this->address_country;
    }

    /**
     * Get formatted client ID for display
     * @return string
     */
    public function client_id_formatted()
    {
        return '#CL' . str_pad($this->id, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Get human-readable region/state name from region code
     * @return string|null
     */
    public function region_name()
    {
        if (!$this->state) {
            return null;
        }

        // Handle "N/A" special case for countries without regions
        if ($this->state === 'N/A') {
            return null;
        }

        $region = Region_Model::where('code', $this->state)->first();
        return $region ? $region->name : $this->state;
    }

    /**
     * Relationships
     */
    #[Relationship]
    #[Ajax_Endpoint_Model_Fetch]
    public function billing_contact()
    {
        return $this->belongsTo(Contact_Model::class, 'billing_contact_id');
    }

    #[Relationship]
    #[Ajax_Endpoint_Model_Fetch]
    public function contacts()
    {
        return $this->hasMany(Contact_Model::class, 'client_id');
    }

    #[Relationship]
    #[Ajax_Endpoint_Model_Fetch]
    public function departments()
    {
        return $this->hasMany(Client_Department_Model::class, 'client_id');
    }

    #[Relationship]
    public function owner()
    {
        return $this->belongsTo(User_Model::class, 'owner_user_id');
    }

    /**
     * Portal: get membership count for this client
     */
    public function portal_member_count(): int
    {
        return Portal_Membership_Model::count_for_client($this->id);
    }

    /**
     * Portal: update last activity timestamp
     */
    public function touch_portal_activity(): void
    {
        $this->portal_last_activity_at = now();
        $this->save();
    }

    #[Relationship]
    public function portal_memberships()
    {
        return $this->hasMany(Portal_Membership_Model::class, 'client_id');
    }

    /**
     * Ajax model fetch - allows JavaScript to load client records.
     *
     * Surface gating is declarative: the class-level #[Auth('is_logged_in')] is evaluated
     * at the ORM seam BEFORE this body runs, and a denial returns the same generic
     * "not found" a missing row does. Record-level rules (ownership, membership scoping,
     * record state) belong HERE, returning false - a gate takes no arguments and can never
     * express "only your own row". This model has none beyond the site scope the site-model
     * abstract already applies.
     */
    #[Ajax_Endpoint_Model_Fetch]
    public static function fetch($id)
    {
        $client = static::find($id);

        if (!$client) {
            return false;
        }

        return $client->to_fetch_array();
    }

    /**
     * The tags column as an array of strings (it is stored as JSON).
     *
     * @return array
     */
    public function tags_array(): array
    {
        if (is_array($this->tags)) {
            return $this->tags;
        }

        return json_decode((string) $this->tags, true) ?: [];
    }

    /**
     * The client display payload: toArray() plus the computed fields the client screens
     * read. Built from an already-loaded instance so both the ORM fetch surface above and
     * the deleted-client endpoint (Frontend_Clients_Controller::fetch_deleted) return the
     * identical shape.
     */
    public function to_fetch_array(): array
    {
        // Start with model's toArray() to get __MODEL and base data
        // Enum properties (status_id__label, status_id__badge) are auto-included
        $data = $this->toArray();

        // tags is stored as a JSON string; the screens (and the @property above) expect
        // the array. Decoding HERE means every consumer of the fetch payload gets the
        // same shape - a caller that decodes for itself is a shape that can drift.
        $data['tags'] = $this->tags_array();

        // Augment with model methods (key must match method name)
        $data['region_name'] = $this->region_name();
        $data['country_name'] = $this->country_name();
        $data['client_id_formatted'] = $this->client_id_formatted();
        $data['portal_member_count'] = $this->portal_member_count();

        // Authorship for the view page's <Record_Author> widget. Resolved server-side
        // (the name may belong to a soft-deleted actor, and the profile URL is gated by
        // the destination's own auth rules for THIS viewer's realm), and asked for
        // explicitly rather than ridden along on every toArray().
        $data['get_created_by_author'] = $this->get_created_by_author();

        return $data;
    }

    /**
     * Remove one of this client's documents: revoke every share of it, THEN soft-delete it
     * into the retention window.
     *
     * BOTH STEPS, ALWAYS, WHICH IS WHY THIS IS ONE METHOD. A client document can be shared
     * with the client's portal users (Shared_Item rows). Deleting the attachment without
     * clearing those rows leaves live shares pointing at a deleted document - the portal
     * side would still list it as shared. Every surface that removes a document (the staff
     * Documents tab and the REST API) must therefore do the same two things in the same
     * order, so the pair is written once, here, rather than trusted to each caller.
     *
     * delete() ENTERS RETENTION - it does not destroy anything. The attachment stays
     * recoverable via get_deleted_attachments()/undelete(); the shares do not come back.
     *
     * @param \App\RSpade\Core\Files\File_Attachment_Model $attachment A document already
     *        resolved against THIS client (use find_attachment()).
     * @return void
     */
    public function remove_document($attachment): void
    {
        Shared_Item_Model::where('item_type', 'File_Attachment_Model')
            ->where('item_id', $attachment->id)
            ->delete();

        $attachment->delete();
    }
}
