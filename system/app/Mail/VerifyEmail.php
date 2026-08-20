<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */


namespace App\Mail;

use App\Models\PendingRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class VerifyEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The pending registration instance.
     *
     * @var \App\Models\PendingRegistration
     */
    public $registration;
    
    /**
     * The verification URL.
     *
     * @var string
     */
    public $url;
    
    /**
     * The expiration time in hours.
     *
     * @var int
     */
    public $expiresInHours;

    /**
     * Create a new message instance.
     *
     * @param \App\Models\PendingRegistration $registration
     * @return void
     */
    public function __construct(PendingRegistration $registration)
    {
        $this->registration = $registration;
        
        $this->url = URL::temporarySignedRoute(
            'auth.verify-email',
            now()->addHours(24),
            ['token' => $registration->verification_token]
        );
        
        $this->expiresInHours = ceil(
            $registration->expires_at->diffInMinutes(now()) / 60
        );
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Verify Your Email Address')
                    ->view('emails.verify-email');
    }
}