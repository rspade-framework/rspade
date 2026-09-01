<?php

namespace Rsx\Emails;

use App\RSpade\Core\Mail\Rsx_Email_Abstract;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Rsx_Portal;

/**
 * Portal_Password_Reset_Email - the single-use reset link for a portal account.
 *
 * TRANSACTIONAL: the recipient asked for it seconds ago, and it is time-limited.
 */
class Portal_Password_Reset_Email extends Rsx_Email_Abstract
{
    const CATEGORY = self::TRANSACTIONAL;

    public function __construct(
        public Portal_User_Model $portal_user,
        public string $reset_url
    ) {
    }

    public function subject(): string
    {
        return 'Reset Your Password';
    }

    public function data(): array
    {
        return [
            'app_name' => config('rsx.name', 'RSpade'),
            'reset_url' => $this->reset_url,
            'expiry_hours' => config('rsx.portal.password_reset_expiry_hours', 1),
        ];
    }

    public static function sample(): static
    {
        $portal_user = new Portal_User_Model();
        $portal_user->email = 'client@example.com';

        return new static($portal_user, rsx_absolute_url(Rsx_Portal::Route('Portal_Password_Reset_Controller::reset', ['token' => 'sample-token'])));
    }
}
