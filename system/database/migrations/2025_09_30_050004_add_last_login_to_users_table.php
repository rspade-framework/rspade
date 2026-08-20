<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Add last_login column to users table
        DB::statement("ALTER TABLE users ADD COLUMN last_login TIMESTAMP NULL DEFAULT NULL");
    }
};