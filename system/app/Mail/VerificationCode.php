<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */


namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VerificationCode extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The user instance.
     *
     * @var \App\Models\User
     */
    public $user;
    
    /**
     * The verification code.
     *
     * @var string
     */
    public $code;
    
    /**
     * The code expiration time in minutes.
     *
     * @var int
     */
    public $expiresInMinutes;

    /**
     * Create a new message instance.
     *
     * @param \App\Models\User $user
     * @param string $code
     * @return void
     */
    public function __construct(User $user, $code)
    {
        $this->user = $user;
        $this->code = $code;
        $this->expiresInMinutes = config('authentication.two_factor.sms.code_lifetime', 10);
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Your Verification Code')
                    ->view('emails.verification-code');
    }
}