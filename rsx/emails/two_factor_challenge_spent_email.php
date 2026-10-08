<?php

namespace Rsx\Emails;

use App\RSpade\Core\Mail\Rsx_Email_Abstract;

/**
 * Two_Factor_Challenge_Spent_Email - tells an account owner that somebody got past their
 * password and then failed their second factor until the challenge was destroyed.
 *
 * Sent by Two_Factor_Notice_Handlers, for staff and portal identities alike. The attempt
 * limits make guessing a second factor slow; this notice is what makes it hopeless, because
 * a sustained attempt cannot stay quiet.
 *
 * SECURITY: a required notice about the recipient's own account, so it reaches an address
 * on the site block list and ignores the recipient opt-out.
 */
class Two_Factor_Challenge_Spent_Email extends Rsx_Email_Abstract
{
    const CATEGORY = self::SECURITY;

    public function __construct(
        public string $email,
        public bool $is_portal = false
    ) {
    }

    public function subject(): string
    {
        return 'Sign-in attempts on your ' . config('rsx.name', 'RSpade') . ' account were blocked';
    }

    public function data(): array
    {
        return [
            'app_name' => config('rsx.name', 'RSpade'),
            'email' => $this->email,
            'is_portal' => $this->is_portal,
        ];
    }

    public static function sample(): static
    {
        return new static('someone@example.com');
    }
}
