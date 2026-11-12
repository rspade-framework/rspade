<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Services;

use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Lib\Portal\Portal_User_Model;
use Rsx\Emails\Api_Key_Notice_Email;
use Rsx\Lib\ActionLog\Action_Log;
use Rsx\Models\Action_Log_Model;

/**
 * Api_Key_Audit_Service - records an API key being created or ended, and tells its holder.
 *
 * Dispatched ONLY by Api_Key_Event_Handlers, once per framework api_key.created /
 * api_key.revoked event for a key that is not temporary; never from anywhere else, so every path
 * is recorded the same way. The run executes as nobody: who acted, from where, and what the key
 * was travel in its params, exactly as the event reported them.
 */
class Api_Key_Audit_Service extends Rsx_Service_Abstract
{
    /**
     * One action-log row on the holder's site - TYPE_API_KEY_CREATED or TYPE_API_KEY_REVOKED,
     * the subject the holder's membership (the users row the key acts as), the actor whoever
     * minted or ended it (null from the command line), the audit session the event's, the
     * metadata the key's facts (api_key_id, name, key_prefix, read_only, scopes, expires_at; how
     * on a revocation) since the key itself may be deleted - and Api_Key_Notice_Email to the
     * holder's sign-in address, both in one Site_Lock::write() section on that site.
     *
     * Every event is announced, whoever acted: a key is a lasting credential that acts as its
     * holder, so the holder hears of each one created or ended on their account - by themself,
     * an administrator, or the command line.
     *
     * @param array $params {event: created|revoked, how: ?revoked|deleted, api_key_id, user_id,
     *        name, key_prefix, read_only, scopes: ?string, expires_at: ?ISO, actor_type: ?string,
     *        actor_id: ?int, audit_session_id: ?int, occurred_at: ISO}
     */
    #[Task('Record an API key event and send its security notice')]
    public static function record_api_key_event(Task_Instance $task, array $params = [])
    {
        $is_created = $params['event'] === 'created';
        $user_id = (int) $params['user_id'];
        $audit_session_id = $params['audit_session_id'] === null ? null : (int) $params['audit_session_id'];

        $holder = User_Model::without_site_scope(fn () => User_Model::find($user_id));

        if ($holder === null) {
            $task->summary("The key holder (user #{$user_id}) no longer exists; nothing recorded.");

            return null;
        }

        $site_id = (int) $holder->site_id;
        $login_user = $holder->login_user_id === null ? null : Login_User_Model::find((int) $holder->login_user_id);
        $actor = static::__actor($params);

        $metadata = [
            'api_key_id' => (int) $params['api_key_id'],
            'name' => (string) $params['name'],
            'key_prefix' => (string) $params['key_prefix'],
            'read_only' => (bool) $params['read_only'],
            'scopes' => $params['scopes'],
            'expires_at' => $params['expires_at'],
        ];
        if (!$is_created) {
            $metadata['how'] = (string) $params['how'];
        }

        $type = $is_created ? Action_Log_Model::TYPE_API_KEY_CREATED : Action_Log_Model::TYPE_API_KEY_REVOKED;
        $ip_address = $audit_session_id === null ? null : (Session::get_audit_session($audit_session_id)['ip_address'] ?? null);
        $notified = false;

        $task->heartbeat();

        Session::set_temporary_site_id($site_id);
        try {
            Site_Lock::write($site_id, function () use ($user_id, $type, $metadata, $actor, $audit_session_id, $login_user, $params, $is_created, $ip_address, &$notified) {
                $subject = User_Model::find($user_id);
                if ($subject === null) {
                    return;
                }

                Action_Log::record($type, $subject, [], $metadata, $actor, $audit_session_id);

                if ($login_user === null) {
                    return;
                }

                (new Api_Key_Notice_Email(
                    (string) $login_user->email,
                    $is_created ? Api_Key_Notice_Email::EVENT_CREATED : (string) $params['how'],
                    (string) $params['name'],
                    (string) $params['key_prefix'],
                    (bool) $params['read_only'],
                    $params['scopes'],
                    $params['expires_at'],
                    (string) $params['occurred_at'],
                    $login_user->timezone,
                    static::__by_whom($actor, $login_user),
                    $ip_address
                ))->to((string) $login_user->email)->send();
                $notified = true;
            }, 'Record an API key event');
        } finally {
            Session::clear_temporary_site_id();
        }

        $task->state(['site_id' => $site_id, 'notified' => $notified]);
        $task->summary("Recorded on site {$site_id}" . ($notified ? '; notice queued.' : '.'));

        return null;
    }

    /**
     * Who did it, as the notice says it: the holder themself, an administrator (named), or the
     * command line (nobody signed in).
     */
    private static function __by_whom(Login_User_Model|Portal_User_Model|null $actor, Login_User_Model $holder): string
    {
        if ($actor === null) {
            return Api_Key_Notice_Email::BY_COMMAND_LINE;
        }

        if ($actor instanceof Login_User_Model && (int) $actor->id === (int) $holder->id) {
            return Api_Key_Notice_Email::BY_YOU;
        }

        return (string) $actor->email;
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
            default => shouldnt_happen('Unknown API-key-event actor type: ' . $params['actor_type']),
        };
    }
}
