<?php

namespace Rsx\Handlers;

use App\RSpade\Core\Models\User_Model;

/**
 * Initial_User_Handlers
 *
 * What THIS application wants for the very first account it ever has.
 *
 * The framework creates that account - credential and site profile, both id 1 - and
 * fires user.initial.created. Everything past "an account exists" is application
 * policy, and this is where an application declares it. The alternative, a migration
 * that inserts rows keyed to user id 1, is the shape to avoid: a migration runs once
 * per database, in a fixed place in history, and cannot know whether the account it
 * is decorating exists yet.
 *
 * Handlers run INLINE in whatever created the account - the first-run setup screen, a
 * migrate on a new install, or the test-suite baseline seed - so this also runs against
 * the test baseline, and a test may rely on the rows it makes.
 *
 * See: php artisan rsx:man initial_user
 */
class Initial_User_Handlers
{
    /**
     * The founder runs this application: the top role.
     *
     * The role is only assigned when the framework left it unset. A caller that chose a
     * role deliberately - the test baseline seeds the most privileged role this
     * application declares - said what it wanted, and this is not the place to
     * overrule it.
     *
     * @param array $data {user: User_Model, login_user: Login_User_Model, site_id: int, source: string}
     * @return void
     */
    #[OnEvent('user.initial.created', priority: 10)]
    public static function make_founder_an_administrator($data)
    {
        /** @var User_Model $user */
        $user = $data['user'];

        if ($user->role_id === null) {
            $user->role_id = User_Model::ROLE_ROOT_ADMIN;
            $user->save();
        }
    }
}
