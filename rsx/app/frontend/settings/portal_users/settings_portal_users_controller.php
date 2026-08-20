<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Frontend\Settings\PortalUsers;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use Rsx\App\Frontend\Settings\PortalUsers\List\Portal_Users_DataGrid;

/**
 * Frontend_Settings_Portal_Users_Controller
 *
 * Backs the global Settings > Portal Users admin screen. Provides the DataGrid
 * fetch endpoint; the global suspend / reactivate account-ban actions are shared
 * endpoints on Frontend_Clients_Controller (portal_user_*) - a single
 * implementation. Per-client access is controlled on the per-client members table
 * (Disable Access), not here.
 */
#[Auth('is_logged_in')]
class Frontend_Settings_Portal_Users_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: Fetch DataGrid data.
     */
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        return Portal_Users_DataGrid::fetch($params);
    }
}
