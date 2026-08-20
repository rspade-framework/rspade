<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Use raw MySQL queries for clarity and auditability (DB::statement with raw SQL,
     * never Schema::table with Blueprint). Migrations must be self-contained.
     *
     * Adds the scopes column to _api_keys. Reserved for future scope enforcement;
     * unused in v1.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("ALTER TABLE _api_keys ADD COLUMN scopes TEXT NULL DEFAULT NULL");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
