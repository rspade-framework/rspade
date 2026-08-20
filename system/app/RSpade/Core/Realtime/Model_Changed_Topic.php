<?php

namespace App\RSpade\Core\Realtime;

use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Realtime\Realtime_Topic_Abstract;
use App\RSpade\Core\Session\Session;

/**
 * Model_Changed_Topic
 *
 * The single topic carrying every opt-in model's change notifications. Published
 * by Realtime_Emissions on transaction commit as {model, id} — a notification-only
 * hint that a record changed, never the record's data. A subscriber refetches
 * through normal Ajax; a delete simply refetches to a not-found/error state.
 *
 * Filter semantics (matched by the existing shallow filter matching):
 *   {model: 'Client_Model', id: 5}  -> exactly that record
 *   {model: 'Client_Model'}         -> every change to that model on the site
 *                                       (collection-level watch)
 *
 * Security stance (owner): subscribed data is assumed insecure; because messages
 * are notification-only, the gate is simply "is a signed-in user" (staff OR
 * portal). The record's own fetch()/portal_fetch() still enforces read access
 * when the subscriber refetches — the notification reveals only that some record
 * of some model changed.
 */
class Model_Changed_Topic extends Realtime_Topic_Abstract
{
    public static function can_subscribe(array $filter = []): bool
    {
        return Session::is_logged_in() || Portal_Session::is_logged_in();
    }
}
