<?php

namespace Rsx\Emails;

use App\RSpade\Core\Mail\Rsx_Email_Abstract;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\TwoFactor\Rsx_Portal_Two_Factor;

/**
 * Portal_Sign_In_Code_Email - the six-digit code that completes a portal sign-in.
 *
 * SECURITY: the recipient set it in motion by typing their password a moment ago, and
 * without it they cannot reach their own account - so it reaches an address on the site
 * block list and ignores the recipient opt-out.
 *
 * The code is in data(), and therefore in the queue row's template data, for as long as the
 * row is retained. That is acceptable for a code that dies with its challenge
 * (rsx.two_factor.challenge_window_minutes); it would not be for a long-lived secret.
 */
class Portal_Sign_In_Code_Email extends Rsx_Email_Abstract
{
    const CATEGORY = self::SECURITY;

    public function __construct(
        public Portal_User_Model $portal_user,
        public string $code
    ) {
    }

    public function subject(): string
    {
        return 'Your sign-in code: ' . $this->code;
    }

    public function data(): array
    {
        return [
            'app_name' => config('rsx.name', 'RSpade'),
            'code' => $this->code,
            'expiry_minutes' => (int) config('rsx.two_factor.challenge_window_minutes'),
        ];
    }

    public static function sample(): static
    {
        $portal_user = new Portal_User_Model();
        $portal_user->email = 'client@example.com';

        return new static($portal_user, '482913');
    }
}
