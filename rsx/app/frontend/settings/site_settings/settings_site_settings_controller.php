<?php

namespace Rsx\App\Frontend\Settings\SiteSettings;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 */
#[Auth('is_logged_in')]
class Frontend_Settings_Site_Settings_Controller extends Rsx_Controller_Abstract
{
    #[Ajax_Endpoint]
    public static function update(Request $request, array $params = [])
    {
        return [
            'message' => 'Site settings updated successfully',
        ];
    }
}
