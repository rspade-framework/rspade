<?php

namespace Rsx\Handlers;

use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Time\Rsx_Time;
use Rsx\Models\Login_User_Password_Reset_Model;
use Rsx\Models\Portal_Password_Reset_Model;

/**
 * Password_Event_Handlers - THE ONE PLACE this application reacts to a password event, so no
 * path that changes a password can forget to.
 *
 * The framework fires login_user.password.changed / portal_user.password.changed after any
 * change of an existing identity's password commits - the user's own change, a reset link, an
 * administrator, the forced change at sign-in, the command line, or a save that stated no cause
 * ('unknown') - with {identity, cause, actor, audit_session_id, ip_address}. This application
 * fires login_user.password.reset_requested / portal_user.password.reset_requested itself, with
 * the same payload shape and cause null, from the one place each "Forgot your password?" page
 * mints a link (its reset flow is the application's, not the framework's).
 *
 * On a CHANGE, here and at once: every open "Forgot your password?" link of the identity ends,
 * whatever made the change, so a link mailed before it cannot undo it. (raw_bulk(): a raw
 * write the lock enforcement does not see, safe from whatever lock context the event fires in.)
 *
 * Then, for every event, one Password_Audit_Service::record_password_event run: an action-log
 * row on EVERY site the identity belongs to (a staff identity's password is one credential
 * across all of them) and, for a change the account's owner made, the security notice email.
 * It is a task because those rows land on several sites, and this handler may run under one
 * site's lock (a #[Writer]), another's, or none - a process holds one site's lock at a time.
 * The audit session travels as an id; the task runs as nobody and records exactly who and where
 * the event said.
 */
class Password_Event_Handlers
{
    /**
     * @param array $data {identity: Login_User_Model, cause: string, actor, audit_session_id, ip_address}
     */
    #[OnEvent('login_user.password.changed', priority: 10)]
    public static function staff_password_changed($data): void
    {
        Login_User_Password_Reset_Model::invalidate_open_for((int) $data['identity']->id);

        static::__dispatch('staff', 'changed', $data);
    }

    /**
     * @param array $data {identity: Portal_User_Model, cause: string, actor, audit_session_id, ip_address}
     */
    #[OnEvent('portal_user.password.changed', priority: 10)]
    public static function portal_password_changed($data): void
    {
        // A portal user id names one row on one site; the read bypass lets this run from a
        // context that declared no site (the command line).
        Portal_Password_Reset_Model::without_site_scope(
            fn () => Portal_Password_Reset_Model::invalidate_open_for((int) $data['identity']->id)
        );

        static::__dispatch('portal', 'changed', $data);
    }

    /**
     * @param array $data {identity: Login_User_Model, cause: null, actor: null, audit_session_id, ip_address}
     */
    #[OnEvent('login_user.password.reset_requested', priority: 10)]
    public static function staff_reset_requested($data): void
    {
        static::__dispatch('staff', 'reset_requested', $data);
    }

    /**
     * @param array $data {identity: Portal_User_Model, cause: null, actor: null, audit_session_id, ip_address}
     */
    #[OnEvent('portal_user.password.reset_requested', priority: 10)]
    public static function portal_reset_requested($data): void
    {
        static::__dispatch('portal', 'reset_requested', $data);
    }

    private static function __dispatch(string $realm, string $event, array $data): void
    {
        $actor = $data['actor'];

        Task::dispatch('Password_Audit_Service', 'record_password_event', [
            'realm' => $realm,
            'event' => $event,
            'identity_id' => (int) $data['identity']->id,
            'cause' => $data['cause'],
            'actor_type' => $actor === null ? null : class_basename($actor),
            'actor_id' => $actor === null ? null : (int) $actor->id,
            'audit_session_id' => $data['audit_session_id'],
            'occurred_at' => Rsx_Time::now_iso(),
        ]);
    }
}
