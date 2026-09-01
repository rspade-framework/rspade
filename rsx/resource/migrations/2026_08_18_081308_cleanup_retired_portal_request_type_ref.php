<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Remove the data debris of the retired Portal_Request_Model.
     *
     * The class was retired in the thread-based Requests rebuild (2026-06-25) but its
     * _type_refs row and 16 referencing rows survived. Since the retired-type-ref
     * hardening (2026-08-18), reading any of those rows throws - which is correct, and
     * also why they must go: the records point at an entity type that no longer exists
     * and can never be read again.
     *
     * The type-ref id is resolved by class_name, never hardcoded - ids are
     * per-environment auto-increments. On a database that never had the debris every
     * statement affects zero rows, which is the intended no-op.
     *
     * This is the RETIRING A MODEL procedure from rsx:man polymorphic. NOTE, for anyone
     * reading this as a pattern: the framework's stance has since narrowed. The
     * REFERENCING ROWS are what must go; the `_type_refs` row itself is inert and is now
     * left in place (`php artisan rsx:type_refs:orphans` reports the rows, and there is
     * no prune command). This migration's registry-row delete stands as executed history.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            DELETE pn FROM portal_notifications pn
            JOIN _type_refs r ON r.id = pn.subject_type
            WHERE r.class_name = 'Portal_Request_Model'
        ");

        DB::statement("
            DELETE fa FROM _file_attachments fa
            JOIN _type_refs r ON r.id = fa.fileable_type
            WHERE r.class_name = 'Portal_Request_Model'
        ");

        DB::statement("DELETE FROM _type_refs WHERE class_name = 'Portal_Request_Model'");
    }
};
