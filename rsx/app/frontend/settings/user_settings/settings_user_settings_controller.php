<?php


namespace Rsx\App\Frontend\Settings\UserSettings;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * User settings for the staff app.
 *
 * The TIMEZONE section of the settings screen does NOT come through here: it posts
 * straight to the framework's App\RSpade\Core\Time\Rsx_Timezone_Controller
 * (get_settings / set_timezone), which is the one setter the early-boot auto-set also
 * uses. A pass-through endpoint here would be a second way to do the same thing.
 *
 * What is left below is the stub for the still-scaffolded controls (notifications,
 * privacy, language/theme/date format), which have no persistence layer yet.
 */
#[Auth('is_logged_in')]
class Frontend_Settings_User_Settings_Controller extends Rsx_Controller_Abstract
{
    /**
     * Update user settings (still a stub - the scaffolded controls only; the timezone
     * section saves through Rsx_Timezone_Controller)
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Ajax_Endpoint]
    public static function update(Request $request, array $params = [])
    {
        // TODO: Implement settings update logic
        return [
            'message' => 'Settings updated successfully',
        ];
    }
}
