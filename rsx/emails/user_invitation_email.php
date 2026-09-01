<?php

namespace Rsx\Emails;

use App\RSpade\Core\Mail\Rsx_Email_Abstract;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;

/**
 * User_Invitation_Email - the staff-side invitation carrying a single-use invite link.
 *
 * TRANSACTIONAL: the link IS the message; an invitation nobody receives is an account
 * nobody can create.
 */
class User_Invitation_Email extends Rsx_Email_Abstract
{
    const CATEGORY = self::TRANSACTIONAL;

    public function __construct(
        public User_Model $user,
        public string $invite_url
    ) {
    }

    public function subject(): string
    {
        return 'You\'re Invited to ' . config('rsx.name', 'RSpade');
    }

    public function data(): array
    {
        return [
            'app_name' => config('rsx.name', 'RSpade'),
            'name' => trim($this->user->first_name . ' ' . $this->user->last_name),
            'invite_url' => $this->invite_url,
            'expiry_days' => config('rsx.auth.invite_expiration_days', 7),
        ];
    }

    public static function sample(): static
    {
        $user = new User_Model();
        $user->first_name = 'Ada';
        $user->last_name = 'Lovelace';
        $user->email = 'ada@example.com';

        return new static($user, rsx_absolute_url(Rsx::Route('Accept_Invite_Controller::index', ['code' => 'sample-invite-code'])));
    }
}
