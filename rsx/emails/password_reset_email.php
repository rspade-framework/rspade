<?php

namespace Rsx\Emails;

use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Lib\Messaging\Mail\Rsx_Email_Abstract;

/**
 * Password_Reset_Email - the single-use "set a new password" link for a staff login identity,
 * sent by Password_Reset_Controller::request() (rsx/app/login/password_reset/).
 *
 * SECURITY: the recipient's own address asked for it seconds ago, it is time-limited, and an
 * account owner locked out of their account must receive it whatever their notification
 * opt-outs or the site block list say.
 */
class Password_Reset_Email extends Rsx_Email_Abstract
{
    const CATEGORY = self::SECURITY;

    public function __construct(
        public Login_User_Model $login_user,
        public string $reset_url
    ) {
    }

    public function subject(): string
    {
        return 'Reset your ' . config('rsx.name', 'RSpade') . ' password';
    }

    public function data(): array
    {
        return [
            'app_name' => config('rsx.name', 'RSpade'),
            'email' => (string) $this->login_user->email,
            'reset_url' => $this->reset_url,
            'expiry_hours' => (int) config('rsx.auth.password_reset_expiry_hours'),
        ];
    }

    /**
     * A fixed illustrative link: sample() reads no database.
     */
    public static function sample(): static
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'ada@example.com';

        return new static($login_user, 'https://app.example.com/password/reset/sample-token');
    }
}
