<?php

namespace Rsx\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use Rsx\Models\Party_Company_Detail_Model;
use Rsx\Models\Party_Person_Detail_Model;
/**
 * Party - the reference Class-Table Inheritance entity (see man detail_tables).
 *
 * Universal fields (name, email, phone, notes) live on this base; type-specific fields
 * live under detail models selected by type_id: PERSON -> party_person, COMPANY ->
 * party_company, GROUP -> no detail (all fields universal). The detail is reached as
 * `$party->party_person` (auto-vivifying) and embedded on fetch for the JS side.
 */


/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: parties
 *
 * @property int $id
 * @property int $site_id
 * @property int $type_id
 * @property string $name
 * @property string $email
 * @property string $phone
 * @property string $notes
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property string $deleted_at
 * @property int $deleted_by_id
 * @property int $deleted_by_type
 * @property string $first_name (detail: party_person_details)
 * @property string $last_name (detail: party_person_details)
 * @property string $title (detail: party_person_details)
 * @property string $date_of_birth (detail: party_person_details)
 * @property string $legal_name (detail: party_company_details)
 * @property string $tax_identifier (detail: party_company_details)
 * @property string $industry (detail: party_company_details)
 * @property int $employee_count (detail: party_company_details)
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
class Party_Model extends Rsx_Site_Model_Abstract
{
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * The shared entity table - grows with every person and company recorded.
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
    const TYPE_PERSON = 1;
    const TYPE_COMPANY = 2;
    const TYPE_GROUP = 3;

    use SoftDeletes;

    protected $table = 'parties';
    protected $fillable = []; // No mass assignment - always explicit

    // Realtime: a party emits its own change so the Party view live-refreshes on edit. Its
    // CTI detail models also touch back to the party (see their realtime_touch()), so a
    // detail-only write refreshes the view too; the base+touch emissions dedupe to one.
    public static $realtime = true;

    public static $enums = [
        'type_id' => [
            1 => ['constant' => 'TYPE_PERSON', 'label' => 'Person', 'badge' => 'bg-primary'],
            2 => ['constant' => 'TYPE_COMPANY', 'label' => 'Company', 'badge' => 'bg-success'],
            3 => ['constant' => 'TYPE_GROUP', 'label' => 'Group', 'badge' => 'bg-secondary'],
        ],
    ];

    public static $detail_tables = [
        'type_id' => [
            self::TYPE_PERSON => Party_Person_Detail_Model::class,
            self::TYPE_COMPANY => Party_Company_Detail_Model::class,
            // TYPE_GROUP absent => no detail row.
        ],
    ];

    /**
     * Get formatted party ID for display
     * @return string
     */
    public function party_id_formatted()
    {
        return '#PA' . str_pad($this->id, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Ajax model fetch - allows JavaScript to load party records. The active detail is
     * eager-embedded by toArray(), so the JS detail accessor resolves with no extra fetch.
     * Unrestricted for development/testing - no auth required.
     */
    #[Ajax_Endpoint_Model_Fetch]
    public static function fetch($id)
    {
        $party = static::find($id);

        if (!$party) {
            return false;
        }

        $data = $party->toArray();
        $data['party_id_formatted'] = $party->party_id_formatted();

        return $data;
    }
}