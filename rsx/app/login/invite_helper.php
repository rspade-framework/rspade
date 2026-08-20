<?php

namespace Rsx\App\Login;

use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;

/**
 * Invite_Helper
 *
 * Helper functions for invitation validation and email matching
 */
class Invite_Helper
{
    /**
     * Check if an invitation is valid for the current user
     *
     * Validates:
     * - Invitation exists
     * - Not expired
     * - Not already accepted
     * - If user is logged in, email must match invitation email
     *
     * @param string $invite_code Invitation code
     * @param bool $require_email_match Require email match if user is logged in (default true)
     * @return array ['valid' => bool, 'invitation' => User_Model|null, 'error' => string|null, 'error_type' => string|null]
     */
    public static function validate_invitation(string $invite_code, bool $require_email_match = true): array
    {
        // Find invitation
        $invitation = User_Model::where('invite_code', $invite_code)->first();

        if (!$invitation) {
            return [
                'valid' => false,
                'invitation' => null,
                'error' => 'No such invitation exists',
                'error_type' => 'not_found',
            ];
        }

        // Check if expired
        if ($invitation->invite_expires_at && $invitation->invite_expires_at < now()) {
            return [
                'valid' => false,
                'invitation' => $invitation,
                'error' => 'This invitation has expired',
                'error_type' => 'expired',
            ];
        }

        // Check if already accepted
        if ($invitation->invite_accepted_at) {
            return [
                'valid' => false,
                'invitation' => $invitation,
                'error' => 'This invitation has already been accepted',
                'error_type' => 'already_accepted',
            ];
        }

        // If user is logged in and email matching is required, check email match
        if ($require_email_match && Session::is_logged_in()) {
            $login_user = Session::get_login_user();

            if ($login_user && $login_user->email !== $invitation->email) {
                return [
                    'valid' => false,
                    'invitation' => $invitation,
                    'error' => 'Email mismatch',
                    'error_type' => 'email_mismatch',
                    'invited_email' => $invitation->email,
                    'current_email' => $login_user->email,
                ];
            }
        }

        // Valid invitation
        return [
            'valid' => true,
            'invitation' => $invitation,
            'error' => null,
            'error_type' => null,
        ];
    }

    /**
     * Check if a specific email address can accept an invitation
     *
     * Used during signup to validate that the email address being used
     * matches the invitation email
     *
     * @param User_Model $invitation Invitation record
     * @param string $email Email address to check
     * @return bool True if email matches invitation email
     */
    public static function can_email_accept_invitation(User_Model $invitation, string $email): bool
    {
        return strtolower(trim($invitation->email)) === strtolower(trim($email));
    }

    /**
     * Get human-readable error message for invitation validation
     *
     * @param array $validation_result Result from validate_invitation()
     * @return string Error message
     */
    public static function get_error_message(array $validation_result): string
    {
        if ($validation_result['valid']) {
            return '';
        }

        return $validation_result['error'] ?? 'Invalid invitation';
    }
}
