<?php

namespace Rsx\Models;

use App\RSpade\Core\Database\DetailTables\Rsx_Detail_Model_Abstract;
use Rsx\Models\Billable_Entry_Model;

/**
 * Billable_Entry_Time_Detail_Model - the fields only a TIME entry has (rsx:man detail_tables).
 *
 * Reached only through $entry->billable_entry_time; never independently fetchable - its
 * authorization is the entry's, and it rides the entry's fetch() payload under __details.
 *
 * `hours` is a quantity, not money (declared `[]`); `hourly_rate` is money, copied from the
 * project when the entry is created and editable on the entry. The entry's amount is
 * hourly_rate x hours, computed by Billable_Entry_Model::save_edit(). A money value submitted
 * for hourly_rate is read through THIS model's money_from_submitted('hourly_rate', ...).
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: billable_entry_time_details
 *
 * @property int $billable_entry_id
 * @property string $created_at
 * @property int $created_by_id
 * @property string $created_by_type type ref: a model class name, stored as a type-ref id
 * @property \App\RSpade\Core\Database\Money\Rsx_Money $hourly_rate money: a value object, two decimal places, never a float
 * @property string $hours decimal
 * @property int $id
 * @property string $updated_at
 * @property int $updated_by_id
 * @property string $updated_by_type type ref: a model class name, stored as a type-ref id
 *
 * @mixin \Eloquent
 */
class Billable_Entry_Time_Detail_Model extends Rsx_Detail_Model_Abstract
{
    protected $table = 'billable_entry_time_details';
    protected $fillable = [];

    protected static $parent_model = Billable_Entry_Model::class;

    public static $columns = [
        'hours' => [],
        'hourly_rate' => ['money' => true],
    ];

    /**
     * The entry this detail belongs to. #[Realtime_Touch]: a detail-only save notifies the
     * entry's subscribers, so its view refreshes.
     */
    #[Relationship]
    #[Realtime_Touch]
    public function billable_entry()
    {
        return $this->belongsTo(Billable_Entry_Model::class, 'billable_entry_id');
    }
}
