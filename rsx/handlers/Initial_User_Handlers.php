<?php

namespace Rsx\Handlers;

use App\RSpade\Core\Env\Rsx_Initial_User;
use App\RSpade\Core\Models\User_Model;
use Rsx\Models\User_Group_Model;

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
     * The group every administrator of this application belongs to. Deletion-protected:
     * a site with no administrators group is a site whose permission screens have
     * nothing to point at.
     */
    public const ADMINISTRATORS_GROUP = 'Administrators';

    /**
     * The founder runs this application: top role, and a member of Administrators.
     *
     * The role is only assigned when the framework left it unset. A caller that chose a
     * role deliberately - the test baseline seeds ROLE_DEVELOPER, which outranks this -
     * said what it wanted, and this is not the place to overrule it.
     *
     * @param array $data {user: User_Model, login_user: Login_User_Model, site_id: int, source: string}
     * @return void
     */
    #[OnEvent('user.initial.created', priority: 10)]
    public static function make_founder_an_administrator($data)
    {
        /** @var User_Model $user */
        $user = $data['user'];
        $site_id = (int) $data['site_id'];
        $connection_name = $user->getConnectionName();

        if ($user->role_id === null) {
            $user->role_id = User_Model::ROLE_ROOT_ADMIN;
            $user->save();
        }

        $group = new User_Group_Model();
        if ($connection_name !== null) {
            $group->setConnection($connection_name);
        }
        $group->site_id = $site_id;
        $group->name = self::ADMINISTRATORS_GROUP;
        $group->description = 'Full access to this application. Created with the first account ('
            . 'user id ' . Rsx_Initial_User::INITIAL_USER_ID . ').';
        $group->deletion_protection = 1;
        $group->save();

        $group->members()->attach($user->id);
    }
}
