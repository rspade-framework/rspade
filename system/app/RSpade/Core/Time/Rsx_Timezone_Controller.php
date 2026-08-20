<?php

namespace App\RSpade\Core\Time;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * RSX:USE
 * Rsx_Timezone_Controller - the signed-in user's timezone preference.
 *
 * ONE setter serves both callers: the early-boot auto-set (the browser's detected
 * zone) and the settings screen (a manual choice, which turns the auto-set off).
 *
 * Usage from JavaScript:
 *     const options  = await Rsx_Timezone_Controller.timezone_options();
 *     const settings = await Rsx_Timezone_Controller.get_settings();
 *     const result   = await Rsx_Timezone_Controller.set_timezone({timezone: 'Europe/Berlin'});
 *
 * AUTHORIZATION: is_logged_in, staff realm. A personal timezone belongs to a
 * login_users row, and a portal account has none (see Rsx_Time::get_user_timezone).
 */
#[Auth('is_logged_in')]
class Rsx_Timezone_Controller extends Rsx_Controller_Abstract
{
    /**
     * Every IANA timezone identifier as Select_Input-native options, labelled with
     * the identifier and its current UTC offset.
     *
     * @return array [{value, label}, ...]
     */
    #[Ajax_Endpoint]
    public static function timezone_options(Request $request, array $params = [])
    {
        $options = [];

        foreach (Rsx_Time::timezone_options() as $identifier => $label) {
            $options[] = ['value' => $identifier, 'label' => $label];
        }

        return $options;
    }

    /**
     * The current user's timezone preference, as the settings screen needs it:
     * the RAW stored preference (null when the user never chose one), the RESOLVED
     * zone the app actually renders in, and the auto-set flag.
     *
     * @return array
     */
    #[Ajax_Endpoint]
    public static function get_settings(Request $request, array $params = [])
    {
        $login_user = Session::get_login_user();
        if (!$login_user) {
            shouldnt_happen('Rsx_Timezone_Controller::get_settings reached with no signed-in login user');
        }

        return [
            'timezone' => $login_user->timezone ?: null,
            'resolved_timezone' => Rsx_Time::get_user_timezone(),
            'timezone_auto' => (bool) $login_user->timezone_auto,
        ];
    }

    /**
     * Set the user's timezone preference.
     *
     * @param array $params ['timezone' => 'Europe/Berlin', 'timezone_auto' => bool|null]
     * @return array ['changed' => bool, 'timezone' => string] - 'changed' says the
     *               RESOLVED zone moved, which is what tells the client to reload.
     */
    #[Ajax_Endpoint]
    public static function set_timezone(Request $request, array $params = [])
    {
        $timezone = $params['timezone'] ?? null;

        if (!is_string($timezone) || $timezone === '') {
            return response_error(Ajax::ERROR_VALIDATION, ['timezone' => 'A timezone is required']);
        }

        $timezone_auto = array_key_exists('timezone_auto', $params) && $params['timezone_auto'] !== null
            ? (bool) $params['timezone_auto']
            : null;

        try {
            $changed = Rsx_Time::set_user_timezone($timezone, $timezone_auto);
        } catch (\InvalidArgumentException $e) {
            // The one expected failure: an identifier the client sent that PHP does
            // not know. Everything else bubbles.
            return response_error(Ajax::ERROR_VALIDATION, ['timezone' => 'Unknown timezone']);
        }

        return [
            'changed' => $changed,
            'timezone' => $timezone,
        ];
    }
}
