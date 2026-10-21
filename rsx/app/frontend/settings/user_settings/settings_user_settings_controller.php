<?php


namespace Rsx\App\Frontend\Settings\UserSettings;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Session\Session;

/**
 * The signed-in user's notification and privacy preferences.
 *
 * The TIMEZONE and THEME sections of the preferences screen do NOT come through here:
 * they post straight to the framework's Rsx_Timezone_Controller and
 * Rsx_Dark_Mode_Controller. This controller owns the rest, and every value it shows is
 * stored somewhere real:
 *
 *   email_notification, email_marketing
 *       The recipient opt-out (Rsx_Mail::is_blocked / block / unblock) for the user's
 *       email address on this site - the address mail to this user is sent to. Only
 *       NOTIFICATION and MARKETING mail honours an opt-out; transactional and security
 *       mail is always delivered, and the screen says so instead of offering a switch.
 *
 *   inapp_task_assigned, profile_visibility
 *       Preference variables on the site's User_Model (rsx:man user_preference_variables),
 *       because both are facts about this person on this site.
 */
#[Auth('is_logged_in')]
class Frontend_Settings_User_Settings_Controller extends Rsx_Controller_Abstract
{
    /** Preference variable: whether "task assigned" in-app notifications are wanted. */
    const VAR_INAPP_TASK_ASSIGNED = 'inapp_notify_task_assigned';

    /** Preference variable: who may see this user's profile. */
    const VAR_PROFILE_VISIBILITY = 'profile_visibility';

    const PROFILE_VISIBILITY_DEFAULT = 'team';

    /**
     * The profile visibility choices, in display order.
     */
    private static function _profile_visibility_options(): array
    {
        return [
            ['value' => 'team', 'label' => 'Team Members', 'description' => 'Your profile is visible to all team members.'],
            ['value' => 'organization', 'label' => 'Organization Only', 'description' => 'Only members of your organization can see your profile.'],
            ['value' => 'private', 'label' => 'Private', 'description' => 'Your profile is hidden from other users.'],
        ];
    }

    /**
     * The address mail to the signed-in user on this site is sent to, or null when the
     * user record has none (then no email preference applies).
     */
    private static function _email_address(): ?string
    {
        $email = trim((string) Session::get_user()->email);

        return $email === '' ? null : $email;
    }

    /**
     * Ajax endpoint: the stored preferences, shaped as the form's values.
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function get_preferences(Request $request, array $params = [])
    {
        $user = Session::get_user();
        $email = self::_email_address();

        return [
            'email_address' => $email,
            'profile_visibility_options' => self::_profile_visibility_options(),
            'form_data' => [
                'email_notification' => $email !== null && !Rsx_Mail::is_blocked($email, Rsx_Mail::NOTIFICATION),
                'email_marketing' => $email !== null && !Rsx_Mail::is_blocked($email, Rsx_Mail::MARKETING),
                'inapp_task_assigned' => (bool) $user->get_variable(self::VAR_INAPP_TASK_ASSIGNED, true),
                'profile_visibility' => $user->get_variable(self::VAR_PROFILE_VISIBILITY, self::PROFILE_VISIBILITY_DEFAULT),
            ],
        ];
    }

    /**
     * Ajax endpoint: save the preferences form.
     *
     * An absent key leaves that preference untouched (the email switches are absent
     * when the user has no address on this site); a present one must be valid.
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Ajax_Endpoint]
    public static function update(Request $request, array $params = [])
    {
        $errors = [];

        $flags = [];
        foreach (['email_notification', 'email_marketing', 'inapp_task_assigned'] as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }
            $value = (string) $params[$key];
            if ($value !== '0' && $value !== '1') {
                $errors[$key] = 'Invalid value';
                continue;
            }
            $flags[$key] = $value === '1';
        }

        $visibility = null;
        if (array_key_exists('profile_visibility', $params)) {
            $visibility = (string) $params['profile_visibility'];
            $allowed = array_column(self::_profile_visibility_options(), 'value');
            if (!in_array($visibility, $allowed, true)) {
                $errors['profile_visibility'] = 'Choose who can see your profile';
            }
        }

        $email = self::_email_address();
        if ($email === null && (isset($flags['email_notification']) || isset($flags['email_marketing']))) {
            $errors['email_notification'] = 'There is no email address on your profile for this site';
        }

        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        $category_for = [
            'email_notification' => Rsx_Mail::NOTIFICATION,
            'email_marketing' => Rsx_Mail::MARKETING,
        ];
        foreach ($category_for as $key => $category) {
            if (!isset($flags[$key])) {
                continue;
            }
            if ($flags[$key]) {
                Rsx_Mail::unblock($email, $category);
            } else {
                Rsx_Mail::block($email, $category);
            }
        }

        $user = Session::get_user();

        if (isset($flags['inapp_task_assigned'])) {
            $user->set_variable(self::VAR_INAPP_TASK_ASSIGNED, $flags['inapp_task_assigned']);
        }

        if ($visibility !== null) {
            $user->set_variable(self::VAR_PROFILE_VISIBILITY, $visibility);
        }

        return [
            'message' => 'Preferences saved',
        ];
    }
}
