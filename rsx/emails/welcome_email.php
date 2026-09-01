<?php

namespace Rsx\Emails;

use App\RSpade\Core\Mail\Rsx_Email_Abstract;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;

/**
 * Welcome_Email - sent to a new staff user once their account exists.
 *
 * TRANSACTIONAL: somebody's account was just created and this is how they learn where
 * to sign in. An opt-out has nothing to say about it.
 */
class Welcome_Email extends Rsx_Email_Abstract
{
    const CATEGORY = self::TRANSACTIONAL;

    public function __construct(
        public User_Model $user,
        public string $login_url
    ) {
    }

    public function subject(): string
    {
        return 'Welcome to ' . config('rsx.name', 'RSpade');
    }

    public function data(): array
    {
        return [
            'app_name' => config('rsx.name', 'RSpade'),
            'name' => $this->user->get_printed_name(),
            'login_url' => $this->login_url,
        ];
    }

    public static function sample(): static
    {
        $user = new User_Model();
        $user->first_name = 'Ada';
        $user->last_name = 'Lovelace';
        $user->email = 'ada@example.com';

        return new static($user, rsx_absolute_url(Rsx::Route('Login_Controller')));
    }
}
