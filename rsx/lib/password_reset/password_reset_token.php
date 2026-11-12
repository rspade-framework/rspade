<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Lib\PasswordReset;

use App\RSpade\Core\Time\Rsx_Time;

/**
 * Password_Reset_Token - the one implementation of an emailed "set a new password" link, shared
 * by the staff reset (Login_User_Password_Reset_Model, login_user_password_resets) and the
 * portal reset (Portal_Password_Reset_Model, portal_password_resets).
 *
 * THE RULES, identical in both realms:
 *
 *   - HASHED AT REST. A link carries a 256-bit random secret; its table stores only
 *     token_hash = SHA-256(secret). A copy of the table opens no account, and a link can be
 *     built only by the instance that minted it (get_secret() is null on any row read back).
 *   - ONE LIVE LINK PER IDENTITY. Minting a link marks every open link of that identity used,
 *     so a second request kills the first email's link.
 *   - EXPIRES at expires_at, minted from the realm's configured window (rsx.auth /
 *     rsx.portal password_reset_expiry_hours). A validity window on a credential, not a timeout:
 *     nothing waits on it.
 *   - SINGLE USE, even when two submissions race: claim() is one conditional UPDATE, and a
 *     caller writes the new password only after its own claim answered true.
 *   - On ANY change of the identity's password invalidate_open_for() marks every open link of
 *     the identity used - called once, by Password_Event_Handlers, on the framework's
 *     password-change event (a completed link, an administrator, the user's own change, the
 *     forced change, the CLI).
 *   - A SPENT link (used, or past expires_at) can never open anything again, and is deleted by
 *     the nightly maintenance sweep (Password_Reset_Link_Service::sweep). query_spent() is that
 *     rule, in one place. A link that is spent and a link that never existed answer the same
 *     "invalid link" page, so deleting one changes nothing a visitor sees.
 *
 * The using model declares the owner column (the identity id) through
 * _password_reset_owner_column() and has the columns token_hash, expires_at and used_at.
 */
trait Password_Reset_Token
{
    /**
     * The secret this instance minted - only ever set by _mint(), never read from the table.
     *
     * @var string|null
     */
    protected $_secret = null;

    /**
     * The column naming the identity the link resets (login_user_id / portal_user_id).
     */
    abstract protected static function _password_reset_owner_column(): string;

    /**
     * Mint a link for an identity: every open link of that identity is used up first.
     *
     * @param int $owner_id The identity's id
     * @param int $expiry_hours How long the link stays usable
     * @param callable|null $fill Sets any further column the model needs (site_id) on the new row
     * @return static The saved row; get_secret() answers the link's secret
     */
    protected static function _mint(int $owner_id, int $expiry_hours, ?callable $fill = null): static
    {
        static::invalidate_open_for($owner_id);

        $secret = bin2hex(random_bytes(32));
        $owner_column = static::_password_reset_owner_column();

        $reset = new static();
        $reset->$owner_column = $owner_id;
        $reset->token_hash = static::hash_secret($secret);
        $reset->expires_at = Rsx_Time::add(Rsx_Time::now_iso(), $expiry_hours * 3600);

        if ($fill !== null) {
            $fill($reset);
        }

        $reset->save();
        $reset->_secret = $secret;

        return $reset;
    }

    /**
     * The stored form of a link's secret.
     *
     * @param string $secret
     * @return string
     */
    public static function hash_secret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * The row a link's secret names, or null. Used, expired and unknown links are told apart
     * by the caller (is_used() / is_expired()); a secret that is no row is null.
     *
     * @param string $secret
     * @return static|null
     */
    public static function find_by_secret(string $secret): ?static
    {
        if ($secret === '') {
            return null;
        }

        return static::where('token_hash', static::hash_secret($secret))->first();
    }

    /**
     * Mark every open link of an identity used. Answers how many it ended.
     *
     * @param int $owner_id
     * @return int
     */
    public static function invalidate_open_for(int $owner_id): int
    {
        return static::where(static::_password_reset_owner_column(), $owner_id)
            ->whereNull('used_at')
            ->raw_bulk()
            ->update(['used_at' => Rsx_Time::to_database(Rsx_Time::now_iso())]);
    }

    /**
     * The links that can never be used again: used, or at or past expires_at. The rule the
     * maintenance sweep deletes by - restated in its delete, so a row is removed only while it
     * is still spent.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function query_spent()
    {
        $now = Rsx_Time::to_database(Rsx_Time::now_iso());

        return static::where(function ($query) use ($now) {
            $query->whereNotNull('used_at')->orWhere('expires_at', '<=', $now);
        });
    }

    /**
     * The secret this instance minted, or null on a row read back from the table.
     *
     * @return string|null
     */
    public function get_secret(): ?string
    {
        return $this->_secret;
    }

    /**
     * Not used and not expired.
     *
     * @return bool
     */
    public function is_valid(): bool
    {
        return !$this->is_used() && !$this->is_expired();
    }

    /**
     * @return bool
     */
    public function is_used(): bool
    {
        return $this->used_at !== null;
    }

    /**
     * @return bool
     */
    public function is_expired(): bool
    {
        return Rsx_Time::is_past($this->expires_at);
    }

    /**
     * Use the link up, if nobody has yet: ONE conditional UPDATE that marks it used only while
     * it is still unused and unexpired. Exactly one of any number of concurrent submissions of
     * the same link wins, so the caller writes the new password only after this answers true.
     *
     * raw_bulk(): the UPDATE itself is the test-and-set; loading the row and saving it would
     * reopen the race this exists to close.
     *
     * @return bool Whether THIS call used it up (false: already used, or expired)
     */
    public function claim(): bool
    {
        $now = Rsx_Time::to_database(Rsx_Time::now_iso());

        $claimed = static::where('id', $this->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', $now)
            ->raw_bulk()
            ->update(['used_at' => $now]);

        $this->refresh();

        return $claimed === 1;
    }
}
