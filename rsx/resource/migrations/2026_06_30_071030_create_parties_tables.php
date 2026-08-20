<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\DetailTables\Rsx_Detail_Table;

/**
 * Parties - the reference Class-Table Inheritance (CTI) entity (see man detail_tables).
 *
 * One base table `parties` holds the universal fields + a `type_id` discriminator. Two
 * detail tables hold the type-specific fields, joined 1:1 to parties by a UNIQUE FK
 * (surrogate id + party_id), created with the Rsx_Detail_Table helper. PERSON and COMPANY
 * have details; GROUP is an absent-detail type (all its fields are universal).
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE parties (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                type_id BIGINT NOT NULL,
                name VARCHAR(255) NULL,
                email VARCHAR(255) NULL,
                phone VARCHAR(50) NULL,
                notes LONGTEXT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                created_by BIGINT DEFAULT NULL,
                updated_by BIGINT DEFAULT NULL,
                deleted_at TIMESTAMP(3) NULL DEFAULT NULL,
                deleted_by BIGINT DEFAULT NULL,

                KEY idx_parties_site_id (site_id),
                KEY idx_parties_type_id (type_id),
                FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // PERSON detail (person-only fields). `name` on the base is composed from these.
        Rsx_Detail_Table::create('party_person_details', 'parties', [
            ['name' => 'first_name', 'type' => 'VARCHAR(255)'],
            ['name' => 'last_name', 'type' => 'VARCHAR(255)'],
            ['name' => 'title', 'type' => 'VARCHAR(255)'],
            ['name' => 'date_of_birth', 'type' => 'DATE'],
        ]);

        // COMPANY detail (entity-only fields).
        Rsx_Detail_Table::create('party_company_details', 'parties', [
            ['name' => 'legal_name', 'type' => 'VARCHAR(255)'],
            ['name' => 'tax_identifier', 'type' => 'VARCHAR(100)'],
            ['name' => 'industry', 'type' => 'VARCHAR(255)'],
            ['name' => 'employee_count', 'type' => 'BIGINT'],
        ]);
    }
};
