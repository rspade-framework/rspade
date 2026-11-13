<?php

namespace Rsx\Handlers;

use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Api_Key_Event_Handlers - THE ONE PLACE this application reacts to an API key being created
 * or ended, so no path that mints or revokes a key can forget to.
 *
 * The framework fires api_key.created and api_key.revoked (Api_Key_Event) after the write
 * commits, whatever made it - the Settings > API Keys screen, an administrator in User
 * Management, the /apidocs tester, the command line - with {api_key, user, actor,
 * audit_session_id, ip_address} (and {how}: revoked | deleted, on api_key.revoked).
 *
 * Two kinds of key are skipped, and announce nothing:
 *   - a key minted by rsx:api:key:temp (api_key->is_temporary): a credential a script mints for
 *     itself for one run, by somebody holding a shell, and announcing every such run would bury
 *     the notices that matter;
 *   - an /apidocs tester key (api_key->is_tester_key()): it is bound to the browser session
 *     that minted it and works only through that console while it is signed in as the key's
 *     user, so a hijacked key gains nothing that session does not already have - there is
 *     nothing for a notice to warn about.
 *
 * Every other event is one Api_Key_Audit_Service::record_api_key_event run: an action-log row
 * on the holder's site and the SECURITY notice email to the holder. It is a task because this
 * handler may run under another site's lock (a #[Writer]) or none (a command), and the row is
 * written under the holder's site's write lock. The key's facts travel in the params because a
 * deleted key's row is gone by the time the task runs; the audit session travels as an id.
 */
class Api_Key_Event_Handlers
{
    /**
     * @param array $data {api_key: Api_Key_Model, user: ?User_Model, actor, audit_session_id, ip_address}
     */
    #[OnEvent('api_key.created', priority: 10)]
    public static function api_key_created($data): void
    {
        static::__dispatch('created', $data);
    }

    /**
     * @param array $data {api_key: Api_Key_Model, user: ?User_Model, actor, audit_session_id, ip_address, how}
     */
    #[OnEvent('api_key.revoked', priority: 10)]
    public static function api_key_revoked($data): void
    {
        static::__dispatch('revoked', $data);
    }

    private static function __dispatch(string $event, array $data): void
    {
        $api_key = $data['api_key'];

        if ((bool) $api_key->is_temporary || $api_key->is_tester_key()) {
            return;
        }

        $actor = $data['actor'];

        Task::dispatch('Api_Key_Audit_Service', 'record_api_key_event', [
            'event' => $event,
            'how' => $data['how'] ?? null,
            'api_key_id' => (int) $api_key->id,
            'user_id' => (int) $api_key->user_id,
            'name' => (string) $api_key->name,
            'key_prefix' => (string) $api_key->key_prefix,
            'read_only' => (bool) $api_key->read_only,
            'scopes' => $api_key->scopes,
            'expires_at' => $api_key->expires_at === null ? null : Rsx_Time::to_iso($api_key->expires_at),
            'actor_type' => $actor === null ? null : class_basename($actor),
            'actor_id' => $actor === null ? null : (int) $actor->id,
            'audit_session_id' => $data['audit_session_id'],
            'occurred_at' => Rsx_Time::now_iso(),
        ]);
    }
}
