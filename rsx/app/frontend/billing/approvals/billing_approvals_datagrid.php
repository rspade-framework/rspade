<?php

namespace Rsx\App\Frontend\Billing\Approvals;

use Illuminate\Database\Eloquent\Builder;
use Rsx\App\Frontend\Billing\List\Billing_DataGrid;
use Rsx\Models\Billable_Entry_Model;

/**
 * Billing_Approvals_DataGrid - the entries waiting for an approver: Billing_DataGrid fixed to
 * SUBMITTED, whatever status the request names. Oldest first, so the queue is worked in order.
 */
class Billing_Approvals_DataGrid extends Billing_DataGrid
{
    protected static string $default_order = 'asc';

    protected static function build_query(array $params): Builder
    {
        // The status key is overwritten, never merged: a request cannot widen the queue.
        return parent::build_query(['status' => Billable_Entry_Model::STATUS_SUBMITTED] + $params);
    }
}
