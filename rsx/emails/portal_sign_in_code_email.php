<?php

namespace Rsx\Emails;

use App\RSpade\Core\Mail\Rsx_Email_Abstract;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Rsx_Portal;

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
 * ONE MESSAGE FOR EVERY SEND. A resend carries the same code and the same link, and the
 * framework restarts their clock on every send, so the message is word for word the original:
 * it states the configured window (rsx.two_factor.challenge_window_minutes), true of every copy.
 * The code leads - it works on any device - and the link follows as bold text rather than a
 * button, because it works only in the browser that started the step. The code's box is
 * styled inline: an application's email.scss replaces the framework's stylesheet wholesale.
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
        public string $link
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
            'expiry_minutes' => max(1, (int) config('rsx.two_factor.challenge_window_minutes')),
        ];
    }

    public static function sample(): static
    {
        $portal_user = new Portal_User_Model();
        $portal_user->email = 'client@example.com';

        return new static(
            $portal_user,
            '482913',
            rsx_absolute_url(Rsx_Portal::Route('Portal_Login_Controller::verify'))
        );
    }
}
