<?php


namespace Rsx\App\Frontend\ActionLogs;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use Rsx\App\Frontend\ActionLogs\List\Action_Logs_DataGrid;

/**
 * Frontend_Action_Logs_Controller - Ajax endpoints for action log listing
 *
 * Action logs are read-only - no create/edit/delete endpoints.
 */
#[Auth('is_logged_in')]
class Frontend_Action_Logs_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: Fetch DataGrid data
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        return Action_Logs_DataGrid::fetch($params);
    }
}
