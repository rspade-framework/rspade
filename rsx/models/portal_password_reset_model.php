<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Lib\Portal\Portal_User_Model;
use Rsx\Lib\PasswordReset\Password_Reset_Token;
/**
 * Portal_Password_Reset_Model - a portal user's emailed "set a new password" link.
 *
 * The token rules (hashed at rest, one live link per portal user, expiry, single-use claim)
 * are Password_Reset_Token's, shared with the staff Login_User_Password_Reset_Model.
 *
 * Flow: the user asks on /password/reset -> create_for_user() mints the link and the email
 * carries it -> /password/reset/:token finds it (find_by_secret()), claim()s it and saves the
 * new password in one write section.
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: portal_password_resets
 *
 * @property string $created_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property string $expires_at
 * @property int $id
 * @property int $portal_user_id
 * @property int $site_id
 * @property string $token_hash
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property string $used_at
 *
 * @mixin \Eloquent
 */
class Portal_Password_Reset_Model extends Rsx_Site_Model_Abstract
{
    use Password_Reset_Token;

    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per reset request - machine-generated, and a sweep target.
     *
     * A DECLARATION, not a runtime gate - a small, well-narrowed query here is still
     * fine. It marks the tables where a bare ->get() deserves a second look, and where
     * ->result_set() is usually the right answer. See the "Do The Whole Job" section
     * of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;

    /**
     * Columns toArray() never sends to the browser. token_hash is a digest of a bearer link's
     * secret; it has no client-side reader.
     *
     * @var string[]
     */
    public static $neverExport = ['token_hash'];

    protected $table = 'portal_password_resets';

    // No enums for this model
    public static $enums = [];

    protected static function _password_reset_owner_column(): string
    {
        return 'portal_user_id';
    }

    /**
     * Mint the portal user's reset link (ending any link they already hold), valid for
     * rsx.portal.password_reset_expiry_hours. get_reset_url() builds its URL - on this
     * instance only, the one that holds the secret.
     *
     * @param Portal_User_Model $portal_user
     * @return static
     */
    public static function create_for_user(Portal_User_Model $portal_user): self
    {
        return static::_mint(
            (int) $portal_user->id,
            (int) config('rsx.portal.password_reset_expiry_hours'),
            function (self $reset) use ($portal_user) {
                $reset->site_id = $portal_user->site_id;
            }
        );
    }

    /**
     * The portal user this link resets.
     *
     * @return Portal_User_Model|null
     */
    public function portal_user(): ?Portal_User_Model
    {
        return Portal_User_Model::find($this->portal_user_id);
    }

    /**
     * The full reset URL, on the reset's own site's portal. Only a portal page of that site
     * requests a reset, so the site has a portal. Only the minting instance can build it.
     *
     * @param array $params Extra query parameters (a threaded Login_Redirect::params())
     * @return string
     */
    public function get_reset_url(array $params = []): string
    {
        if ($this->get_secret() === null) {
            shouldnt_happen('Portal password reset link built from a row that did not mint it');
        }

        $url = \App\RSpade\Lib\Portal\Rsx_Portal::Route(
            'Portal_Password_Reset_Controller::reset',
            ['token' => $this->get_secret()] + $params,
            site_id: (int) $this->site_id
        );

        if ($url === false) {
            shouldnt_happen('Portal password reset link for a site with no client portal');
        }

        return rsx_absolute_url($url);
    }
}
