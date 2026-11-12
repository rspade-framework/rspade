<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Services;

use App\RSpade\Core\Auth\Password_Change;
use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Lib\Portal\Portal_User_Model;
use Rsx\Emails\Password_Changed_Email;
use Rsx\Lib\ActionLog\Action_Log;
use Rsx\Models\Action_Log_Model;

/**
 * Password_Audit_Service - records a password event and sends its security notice.
 *
 * Dispatched ONLY by Password_Event_Handlers, once per framework password-change event and per
 * "Forgot your password?" request; never from anywhere else, so every path is recorded the same
 * way. The run executes as nobody: who acted and from where travel in its params, exactly as the
 * event reported them.
 */
class Password_Audit_Service extends Rsx_Service_Abstract
{
    /**
     * The causes whose notice goes out: the account's OWNER chose the new password (or the save
     * stated no cause, which is told rather than guessed). A password somebody else set - an
     * administrator, the command line - is handed over by that person, and the owner replacing
     * it at the next sign-in (forced_change) sends the notice then.
     */
    const NOTIFIED_CAUSES = [
        Password_Change::CAUSE_SELF,
        Password_Change::CAUSE_RESET_LINK,
        Password_Change::CAUSE_FORCED_CHANGE,
        Password_Change::CAUSE_UNKNOWN,
    ];

    /**
     * One action-log row on every site the identity belongs to (TYPE_PASSWORD_CHANGED with
     * metadata.cause, or TYPE_PASSWORD_RESET_REQUESTED), its subject the affected person's
     * membership of that site (a portal user on its own site), its actor whoever acted - the
     * identity itself for a reset link or the forced change, where nobody is signed in but the
     * owner proved who they are - and its audit session the event's. Then, for a change in
     * NOTIFIED_CAUSES, Password_Changed_Email to the identity's own address.
     *
     * Each row is written in its own Site_Lock::write() section on its site, the site declared
     * for that section only. The email is queued on site 0 for a staff identity (no site is the
     * owner's "home"; the 2FA notice does the same) and on the portal's site for a portal user.
     *
     * @param array $params {realm: staff|portal, event: changed|reset_requested, identity_id,
     *        cause: ?string, actor_type: ?string, actor_id: ?int, audit_session_id: ?int,
     *        occurred_at: ISO}
     */
    #[Task('Record a password event and send its security notice')]
    public static function record_password_event(Task_Instance $task, array $params = [])
    {
        $realm = (string) $params['realm'];
        $is_change = $params['event'] === 'changed';
        $cause = $params['cause'];
        $audit_session_id = $params['audit_session_id'] === null ? null : (int) $params['audit_session_id'];
        $identity_id = (int) $params['identity_id'];

        $identity = $realm === 'portal'
            ? Portal_User_Model::without_site_scope(fn () => Portal_User_Model::find($identity_id))
            : Login_User_Model::find($identity_id);

        if ($identity === null) {
            $task->summary("The {$realm} identity #{$identity_id} no longer exists; nothing recorded.");

            return null;
        }

        $actor = static::__actor($params) ?? (
            in_array($cause, [Password_Change::CAUSE_RESET_LINK, Password_Change::CAUSE_FORCED_CHANGE], true) || !$is_change
                ? $identity
                : null
        );

        $type = $is_change ? Action_Log_Model::TYPE_PASSWORD_CHANGED : Action_Log_Model::TYPE_PASSWORD_RESET_REQUESTED;
        $metadata = $is_change ? ['cause' => $cause] : [];

        // [site_id => subject id]: a staff identity's memberships (one per site, so bounded by
        // the number of sites), or the portal user on its one site.
        $subjects = [];
        if ($realm === 'portal') {
            $subjects[(int) $identity->site_id] = (int) $identity->id;
        } else {
            $memberships = User_Model::without_site_scope(
                fn () => User_Model::where('login_user_id', $identity_id)->orderBy('site_id')->get()
            );
            foreach ($memberships as $membership) {
                $subjects[(int) $membership->site_id] = (int) $membership->id;
            }
        }

        $recorded = 0;
        foreach ($subjects as $site_id => $subject_id) {
            $task->heartbeat();

            Session::set_temporary_site_id($site_id);
            try {
                Site_Lock::write($site_id, function () use ($realm, $subject_id, $type, $metadata, $actor, $audit_session_id, &$recorded) {
                    $subject = $realm === 'portal' ? Portal_User_Model::find($subject_id) : User_Model::find($subject_id);
                    if ($subject === null) {
                        return;
                    }

                    Action_Log::record($type, $subject, [], $metadata, $actor, $audit_session_id);
                    $recorded++;
                }, 'Record a password event');
            } finally {
                Session::clear_temporary_site_id();
            }
        }

        $notified = false;
        if ($is_change && in_array($cause, self::NOTIFIED_CAUSES, true)) {
            $notice_site_id = $realm === 'portal' ? (int) $identity->site_id : 0;
            $ip_address = $audit_session_id === null ? null : (Session::get_audit_session($audit_session_id)['ip_address'] ?? null);

            Session::set_temporary_site_id($notice_site_id);
            try {
                Site_Lock::write($notice_site_id, fn () => (new Password_Changed_Email(
                    (string) $identity->email,
                    (string) $cause,
                    (string) $params['occurred_at'],
                    $realm === 'portal' ? null : $identity->timezone,
                    $ip_address,
                    $realm === 'portal'
                ))->to((string) $identity->email)->send(), 'Password changed notice');
            } finally {
                Session::clear_temporary_site_id();
            }
            $notified = true;
        }

        $task->state(['recorded' => $recorded, 'notified' => $notified]);
        $task->summary("Recorded on {$recorded} site(s)" . ($notified ? '; notice queued.' : '.'));

        return null;
    }

    /**
     * The acting identity the event named, or null.
     */
    private static function __actor(array $params): Login_User_Model|Portal_User_Model|null
    {
        if ($params['actor_id'] === null) {
            return null;
        }

        return match ($params['actor_type']) {
            'Login_User_Model' => Login_User_Model::find((int) $params['actor_id']),
            'Portal_User_Model' => Portal_User_Model::without_site_scope(fn () => Portal_User_Model::find((int) $params['actor_id'])),
            default => shouldnt_happen('Unknown password-event actor type: ' . $params['actor_type']),
        };
    }
}
