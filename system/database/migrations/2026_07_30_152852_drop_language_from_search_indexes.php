<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop the dead `language` column from _search_indexes.
 *
 * The column (VARCHAR(10), added by create_search_indexes_table) was never written by any
 * part of the extraction pipeline - its sole reader was Search_Index_Model::scopeByLanguage(),
 * a dead scope removed alongside this migration. No language detection ships, so the column
 * (and its idx_language index) is always NULL. Forward-only; drop the index before the column.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("DROP INDEX idx_language ON _search_indexes");
        DB::statement("ALTER TABLE _search_indexes DROP COLUMN language");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
