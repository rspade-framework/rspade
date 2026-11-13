<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Models;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Rsx;
use Rsx\Lib\PasswordReset\Password_Reset_Token;
/**
 * Login_User_Password_Reset_Model - a staff login identity's emailed "set a new password" link
 * (the "Forgot your password?" flow on /password/reset).
 *
 * NOT site-scoped: a login identity spans every site it belongs to, and so does its password.
 * The token rules (hashed at rest, one live link per identity, expiry from
 * rsx.auth.password_reset_expiry_hours, single-use claim, every other open link ended on
 * completion) are Password_Reset_Token's, shared with Portal_Password_Reset_Model.
 *
 * ANY change of the identity's password ends every open link (invalidate_open_for(), called by
 * Password_Event_Handlers on login_user.password.changed), so a link mailed before an
 * administrator's reset - or any other change - cannot undo it.
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: login_user_password_resets
 *
 * @property string $created_at
 * @property int $created_by_id
 * @property string $created_by_type type ref: a model class name, stored as a type-ref id
 * @property string $expires_at
 * @property int $id
 * @property int $login_user_id
 * @property string $token_hash never exported to the browser
 * @property string $updated_at
 * @property int $updated_by_id
 * @property string $updated_by_type type ref: a model class name, stored as a type-ref id
 * @property string $used_at
 *
 * @mixin \Eloquent
 */
class Login_User_Password_Reset_Model extends Rsx_Model_Abstract
{
    use Password_Reset_Token;

    /**
     * UNBOUNDED: one row per reset request - machine-generated.
     *
     * @var bool
     */
    public static $unbounded = true;

    /**
     * token_hash is a digest of a bearer link's secret; it has no client-side reader.
     */
    public static $columns = [
        'token_hash' => ['never_export' => true],
    ];

    protected $table = 'login_user_password_resets';

    protected static function _password_reset_owner_column(): string
    {
        return 'login_user_id';
    }

    /**
     * Mint the identity's reset link (ending any link it already holds), valid for
     * rsx.auth.password_reset_expiry_hours. get_reset_url() builds its URL - on this instance
     * only, the one that holds the secret.
     *
     * @param Login_User_Model $login_user
     * @return static
     */
    public static function create_for(Login_User_Model $login_user): self
    {
        return static::_mint((int) $login_user->id, (int) config('rsx.auth.password_reset_expiry_hours'));
    }

    /**
     * The identity this link resets (null once it has been deleted).
     *
     * @return Login_User_Model|null
     */
    public function login_user(): ?Login_User_Model
    {
        return Login_User_Model::where('id', $this->login_user_id)->first();
    }

    /**
     * The absolute /password/reset/:token URL. Only the minting instance can build it.
     *
     * @return string
     */
    public function get_reset_url(): string
    {
        if ($this->get_secret() === null) {
            shouldnt_happen('Staff password reset link built from a row that did not mint it');
        }

        return rsx_absolute_url(Rsx::Route('Password_Reset_Controller::reset', ['token' => $this->get_secret()]));
    }
}
