<?php

namespace Rsx\Emails;

use App\RSpade\Core\Mail\Rsx_Email_Abstract;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Portal_Sign_In_Code_Email - the six-digit code, and its sign-in link, that complete a portal
 * sign-in.
 *
 * The two are one answer delivered twice (Rsx_Portal_Two_Factor::issued_link()): typing the
 * digits, or opening the link in the browser where the password was typed, completes the
 * sign-in. The link works only in that browser; anywhere else it says to type the code.
 *
 * SECURITY: the recipient set it in motion by typing their password a moment ago, and
 * without it they cannot reach their own account - so it reaches an address on the site
 * block list and ignores the recipient opt-out.
 *
 * A resend while the code is still live carries the SAME code and link ($again): the framework keeps one
 * code per portal user, and every send restarts its rsx.two_factor.challenge_window_minutes
 * clock - so the minutes until $expires_at are the full window on every copy.
 *
 * The code and the link are in data(), and therefore in the queue row's template data, for as
 * long as the row is retained. That is acceptable for a pair that dies within minutes; it
 * would not be for a long-lived secret.
 */
class Portal_Sign_In_Code_Email extends Rsx_Email_Abstract
{
    const CATEGORY = self::SECURITY;

    public function __construct(
        public Portal_User_Model $portal_user,
        public string $code,
        public string $link,
        public string $expires_at,
        public bool $again = false
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
            // Absolute and signed already (issued_link()).
            'link' => $this->link,
            'again' => $this->again,
            // Rounded up, and never "0 minutes": a code with seconds left still works.
            'expiry_minutes' => max(1, (int) ceil(Rsx_Time::seconds_until($this->expires_at) / 60)),
        ];
    }

    public static function sample(): static
    {
        $portal_user = new Portal_User_Model();
        $portal_user->email = 'client@example.com';

        return new static(
            $portal_user,
            '482913',
            rsx_absolute_url(Rsx_Portal::Route('Portal_Login_Controller::verify')),
            Rsx_Time::add(Rsx_Time::now_iso(), (int) config('rsx.two_factor.challenge_window_minutes') * 60)
        );
    }
}
