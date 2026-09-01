<?php

namespace Rsx\Emails;

use App\RSpade\Core\Mail\Rsx_Email_Abstract;
use App\RSpade\Core\Portal\Rsx_Portal;

/**
 * Portal_Invitation_Email - invites somebody into a client's portal.
 *
 * Two shapes, one template. A NEW recipient gets a registration link that onboards and
 * joins in one step; somebody who already has a portal account gets a sign-in link and
 * finds the invitation waiting on their dashboard. $existing_account selects which, and
 * it changes the subject line as well as the body.
 *
 * TRANSACTIONAL: an invitation is a direct answer to somebody granting access.
 */
class Portal_Invitation_Email extends Rsx_Email_Abstract
{
    const CATEGORY = self::TRANSACTIONAL;

    /**
     * @param string $registration_url Where the recipient goes: register, or sign in.
     * @param string $portal_name The client whose portal this is - in the subject line.
     * @param bool $existing_account True when the recipient already has a portal login.
     * @param string|null $app_name Branding override; the app's own name when null.
     */
    public function __construct(
        public string $registration_url,
        public string $portal_name,
        public bool $existing_account = false,
        public ?string $app_name = null
    ) {
    }

    public function subject(): string
    {
        if ($this->existing_account) {
            return 'You\'ve Been Invited to ' . $this->portal_name . '\'s Portal';
        }

        return 'You\'re Invited to the ' . $this->portal_name . ' Portal';
    }

    public function data(): array
    {
        return [
            'app_name' => $this->app_name ?? config('rsx.name', 'RSpade'),
            'registration_url' => $this->registration_url,
            'expiry_days' => config('rsx.portal.invitation_expiry_days', 7),
            'existing_account' => $this->existing_account,
            'client_name' => $this->portal_name,
        ];
    }

    public static function sample(): static
    {
        return new static(
            rsx_absolute_url(Rsx_Portal::Route('Portal_Register_Controller', ['code' => 'sample-invitation-code'])),
            'Northwind Trading',
        );
    }
}
