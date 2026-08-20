<?php

namespace Rsx\Models;

use App\RSpade\Core\Database\DetailTables\Rsx_Detail_Model_Abstract;
use Rsx\Models\Party_Model;

/**
 * Party_Company_Detail_Model - company-only fields for a Party (see man detail_tables).
 * Reached only through $party->party_company; never independently fetchable.
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: party_company_details
 *
 * @property int $id
 * @property int $party_id
 * @property string $legal_name
 * @property string $tax_identifier
 * @property string $industry
 * @property int $employee_count
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
class Party_Company_Detail_Model extends Rsx_Detail_Model_Abstract
          {
    protected $table = 'party_company_details';
    protected $fillable = [];

    public static $enums = [];

    protected static $parent_model = Party_Model::class;

    // Realtime: a change to this detail touches its parent party so the Party view
    // live-refreshes on a detail-only edit. $realtime = true is required for the touch
    // cascade to run at all (the save/delete hook only walks realtime_touch() for an
    // opt-in model); the detail's own {model,id} emission has no subscriber and is
    // harmless, and the party emission dedupes with the base party's own save.
    public static $realtime = true;

    /**
     * @return \App\RSpade\Core\Database\Models\Rsx_Model_Abstract[]
     */
    public function realtime_touch(): array
    {
        if (!$this->party_id) {
            return [];
        }
        $party = Party_Model::withTrashed()->find($this->party_id);

        return $party ? [$party] : [];
    }
}
