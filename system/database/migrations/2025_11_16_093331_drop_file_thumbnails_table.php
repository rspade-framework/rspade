<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Drop file_thumbnails table - no longer needed.
     * Thumbnail caching is now filesystem-only for simplicity and performance.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("DROP TABLE IF EXISTS file_thumbnails");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
