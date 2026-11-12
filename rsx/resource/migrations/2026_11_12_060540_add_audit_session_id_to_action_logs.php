<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * action_logs.audit_session_id - where each logged act was done from.
 *
 * The acting context of the row (Session::get_audit_session_id(): the sign-in, the client
 * address, the user agent, an impersonator), stamped by Action_Log::record(). NULL where
 * nobody was acting - a command, a plain task. No foreign key: an audit session is never
 * deleted. The address is read back through Session::get_audit_session() and never copied
 * onto the row.
 *
 * The actor index serves "everything this person did" (a user's history in User Management),
 * the subject index already present serves "everything done to this record".
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            ALTER TABLE action_logs
                ADD COLUMN audit_session_id BIGINT NULL DEFAULT NULL,
                ADD INDEX idx_action_logs_audit_session_id (audit_session_id),
                ADD INDEX idx_action_logs_actor (actor_type, actor_id)
        ");
    }
};
