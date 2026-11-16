<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * projects.description, tasks.description and demo_products.description become
 * description_html.
 *
 * Each column stores sanitized HTML (its model declares it Rich_Text), and a column whose
 * stored value is HTML is named `<thing>_html`, so the name tells a reader of any layer - a
 * query, a payload, an API body - that the string is markup. The rename moves the column
 * only: no index names it, and its contents are unchanged.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE projects RENAME COLUMN description TO description_html");
        DB::statement("ALTER TABLE tasks RENAME COLUMN description TO description_html");
        DB::statement("ALTER TABLE demo_products RENAME COLUMN description TO description_html");
    }
};
