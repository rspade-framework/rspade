<?php

namespace Rsx\Emails;

use App\RSpade\Core\Mail\Rsx_Email_Abstract;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Signup_Verification_Email - the code and the link that confirm a new account's address.
 *
 * Sent by Login_Controller::send_code() for an address-verification challenge: a self-signup
 * (Signup_Controller::submit(), rsx.auth.self_signup), or the password sign-in of an identity
 * whose address was never verified. The two are one answer delivered twice: typing the six
 * digits on the verify screen, or opening the link in the browser that started the step,
 * completes it - the framework marks the address verified and signs the identity in. The link
 * works only in that browser; anywhere else it says to type the code.
 *
 * SECURITY: the recipient set it in motion a moment ago and cannot reach their new account
 * without it - so it reaches an address on the site block list and ignores the recipient
 * opt-out.
 *
 * A resend while the code is live carries the SAME code and link ($again), so the email says
 * so and states the minutes left until $expires_at, which a resend does not move.
 *
 * The code and the link are in data(), and therefore in the queue row's template data, for as
 * long as the row is retained. Acceptable for a pair that dies within minutes; never for a
 * long-lived secret.
 */
class Signup_Verification_Email extends Rsx_Email_Abstract
{
    const CATEGORY = self::SECURITY;

    public function __construct(
        public Login_User_Model $login_user,
        public string $code,
        public string $link,
        public string $expires_at,
        public bool $again = false
    ) {
    }

    public function subject(): string
    {
        return 'Confirm your email: ' . $this->code;
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
        $login_user = new Login_User_Model();
        $login_user->email = 'new.owner@example.com';

        return new static(
            $login_user,
            '482913',
            rsx_absolute_url(Rsx::Route('Login_Controller::verify')),
            Rsx_Time::add(Rsx_Time::now_iso(), (int) config('rsx.two_factor.challenge_window_minutes') * 60)
        );
    }
}
