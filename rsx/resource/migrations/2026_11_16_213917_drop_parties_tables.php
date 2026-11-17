<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Retire the Party entity: drop its three tables and the rows that point at it.
     *
     * The detail tables reference `parties` by foreign key, so they are dropped first.
     *
     * Party records were the subject or actor of action log entries (types 40-42) and,
     * through `$revisions`, the record or root of revision rows - as were its two detail
     * models. Those rows point at an entity type that no longer exists and can never be
     * read again, so they are deleted here (an action log's `action_log_related` rows
     * follow by ON DELETE CASCADE; related rows naming a party under another subject go
     * explicitly). This is the RETIRING A MODEL procedure from rsx:man polymorphic: the
     * class names are string literals resolved through `_type_refs`, and the registry
     * rows themselves are left in place.
     *
     * On a database that never held a party every DELETE affects zero rows.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("DROP TABLE party_person_details");
        DB::statement("DROP TABLE party_company_details");
        DB::statement("DROP TABLE parties");

        $retired = "('Party_Model', 'Party_Person_Detail_Model', 'Party_Company_Detail_Model')";

        DB::statement("
            DELETE al FROM action_logs al
            JOIN _type_refs r ON r.id = al.subject_type
            WHERE r.class_name IN {$retired}
        ");

        DB::statement("
            DELETE al FROM action_logs al
            JOIN _type_refs r ON r.id = al.actor_type
            WHERE r.class_name IN {$retired}
        ");

        DB::statement("
            DELETE alr FROM action_log_related alr
            JOIN _type_refs r ON r.id = alr.related_type
            WHERE r.class_name IN {$retired}
        ");

        DB::statement("
            DELETE rv FROM _revisions rv
            JOIN _type_refs r ON r.id = rv.record_type
            WHERE r.class_name IN {$retired}
        ");

        DB::statement("
            DELETE rv FROM _revisions rv
            JOIN _type_refs r ON r.id = rv.root_type
            WHERE r.class_name IN {$retired}
        ");
    }
};
