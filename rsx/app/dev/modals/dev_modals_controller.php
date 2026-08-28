<?php

namespace Rsx\App\Dev\Modals;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Modal showcase - CLOSED: no one can reach it, by declaration.
 */
#[Auth('closed')]
class Dev_Modals_Controller extends Rsx_Controller_Abstract
{
    /** The code the PIN demo accepts. */
    private const DEMO_PIN = '123456';

    /**
     * Handle modals feature
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Route('/dev/modals')]
    public static function index(Request $request, array $params = [])
    {
        $data = [
            // Add your data here
        ];

        return rsx_view('Dev_Modals', $data);
    }

    /**
     * Ajax endpoint: the modal-form demo's submit target (Test_Modal_Form).
     *
     * It persists nothing - it exists to be the ONE place the demo form's rules live.
     * Every rule is checked here, on the server, and comes back as a
     * response_form_error() whose keys are the form's own input names, so the form
     * pins each message under its field and raises its top alert. The form itself
     * checks nothing: a client copy of a rule masks the day the server's copy goes
     * missing.
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Ajax_Endpoint]
    public static function register_user(Request $request, array $params = [])
    {
        $name = trim($params['name'] ?? '');
        $email = trim($params['email'] ?? '');
        $role = trim($params['role'] ?? '');

        $errors = [];

        if (strlen($name) < 3) {
            $errors['name'] = 'Name must be at least 3 characters.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }

        if (!in_array($role, ['admin', 'manager', 'user', 'guest'], true)) {
            $errors['role'] = 'Select a role.';
        }

        if ($errors) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        return [
            'name' => $name,
            'email' => $email,
            'role' => $role,
        ];
    }

    /**
     * Ajax endpoint: the PIN demo's submit target (Pin_Verification_Form).
     *
     * Completeness AND correctness are both checked here. "All six digits entered" is
     * a rule the server can see, so the input does not duplicate it.
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Ajax_Endpoint]
    public static function verify_pin(Request $request, array $params = [])
    {
        $pin = trim($params['pin'] ?? '');

        if (strlen($pin) !== 6) {
            return response_form_error('Verification failed.', [
                'pin' => 'Enter all 6 digits.',
            ]);
        }

        if ($pin !== self::DEMO_PIN) {
            return response_form_error('Verification failed.', [
                'pin' => 'Incorrect PIN. (Hint: try ' . self::DEMO_PIN . ')',
            ]);
        }

        return [
            'pin' => $pin,
            'verified' => true,
        ];
    }
}
