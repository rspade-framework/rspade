<?php

namespace Rsx\App\Frontend\Reports;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Project_Model;

/**
 */
#[Auth('is_logged_in')]
class Frontend_Reports_Controller extends Rsx_Controller_Abstract
{
    /**
     * Real, site-scoped summary counts for the Reports overview strip.
     *
     * Only figures with a backing model are surfaced (D4 honesty). Revenue and
     * hours-tracked had no data layer and were removed rather than faked.
     */
    #[Ajax_Endpoint]
    public static function report_stats(Request $request, array $params = [])
    {
        return [
            'active_clients' => Client_Model::where('status_id', Client_Model::STATUS_ACTIVE)->count(),
            'active_projects' => Project_Model::where('status', Project_Model::STATUS_ACTIVE)->count(),
            'total_contacts' => Contact_Model::count(),
        ];
    }
}
