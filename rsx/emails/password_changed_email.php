<?php

namespace Rsx\Emails;

use App\RSpade\Core\Auth\Password_Change;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Lib\Messaging\Mail\Rsx_Email_Abstract;

/**
 * Password_Changed_Email - "the password for your account was changed", to the account's own
 * address, for staff and portal identities alike. Sent by Password_Audit_Service from the
 * framework's password-change events (Password_Event_Handlers) whenever the account's owner
 * chose the new password - their own change, a reset link, the forced change at sign-in - or
 * the change stated no cause. A password somebody ELSE set (an administrator, the command line)
 * sends nothing: that person hands it over, and the owner's own replacement of it at the next
 * sign-in sends this notice.
 *
 * It says what happened, when and from which address, and what to do if it was not them.
 *
 * SECURITY: a required notice about the recipient's own account, so it reaches an address on
 * the site block list, ignores the recipient opt-out and carries no unsubscribe link (the
 * builder passes none for this category, so the shared layout's footer has nothing to offer).
 */
class Password_Changed_Email extends Rsx_Email_Abstract
{
    const CATEGORY = self::SECURITY;

    /**
     * @param string $email The account's address
     * @param string $cause Password_Change::CAUSE_*
     * @param string $changed_at When the change was saved (ISO)
     * @param string|null $timezone The zone to print $changed_at in; null = the default
     * @param string|null $ip_address Where it was made from; null when nobody's address was recorded
     * @param bool $is_portal A client-portal account
     */
    public function __construct(
        public string $email,
        public string $cause,
        public string $changed_at,
        public ?string $timezone,
        public ?string $ip_address,
        public bool $is_portal = false
    ) {
    }

    public function subject(): string
    {
        return 'Your ' . config('rsx.name', 'RSpade') . ' password was changed';
    }

    public function data(): array
    {
        return [
            'app_name' => config('rsx.name', 'RSpade'),
            'email' => $this->email,
            'is_portal' => $this->is_portal,
            'what_happened' => match ($this->cause) {
                Password_Change::CAUSE_SELF => 'was changed from your account settings',
                Password_Change::CAUSE_RESET_LINK => 'was reset using a link emailed to this address',
                Password_Change::CAUSE_FORCED_CHANGE => 'was changed at sign-in, where a new password was required',
                default => 'was changed',
            },
            'changed_when' => Rsx_Time::format_timestamp($this->changed_at, $this->timezone),
            'ip_address' => $this->ip_address,
        ];
    }

    public static function sample(): static
    {
        return new static(
            'someone@example.com',
            Password_Change::CAUSE_RESET_LINK,
            '2026-11-12T14:30:00.000Z',
            null,
            '203.0.113.7'
        );
    }
}
