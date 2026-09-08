<?php

namespace Rsx\Handlers;

use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;

/**
 * User_Profile_Url_Handlers
 *
 * Where THIS application shows a staff user. The framework's User_Model asks the resolve hook
 * `user.view_profile_url` because a profile link names a screen, and screens are the
 * application's; with no answer the name renders as plain text in <Record_Author>.
 *
 * Every destination is resolved through the gates declared on the DESTINATION
 * (Auth_Gates::accessible_route), never by a role test of its own:
 *
 * - Portal realm: null. A client-portal user has no business reaching a staff
 *   administration screen, and the portal registry contains no staff surface.
 * - Own record: the viewer's own profile page - reachable by every signed-in staff user, so
 *   authorship of your own records always links somewhere useful even without
 *   user-management rights.
 * - Anyone else: the user-management detail screen, gated 'can_manage_users'. A viewer
 *   without that permission gets null and the name renders as plain text.
 *
 * See: php artisan rsx:man event_hooks
 */
class User_Profile_Url_Handlers
{
    /**
     * @param array $data {user: User_Model}
     * @return string|null a URL, or null to decline
     */
    #[OnEvent('user.view_profile_url')]
    public static function resolve_view_profile_url(array $data): ?string
    {
        $user = $data['user'];

        if (Rsx_Portal::is_portal_request()) {
            return null;
        }

        if ((int) $user->id === (int) Session::get_user_id()) {
            $own = Auth_Gates::accessible_route('Settings_Profile_Display_Action', Auth_Gates::REALM_STAFF);
            if ($own !== null) {
                return $own;
            }
        }

        return Auth_Gates::accessible_route(
            'Settings_User_Management_View_Action',
            Auth_Gates::REALM_STAFF,
            (int) $user->id
        );
    }
}
