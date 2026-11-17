<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\DetailTables\Rsx_Detail_Table;

/**
 * Billing - billable entries (time, expenses, fixed fees) and the invoices that bill them.
 *
 * `invoices` - one per client invoice. invoice_number is sequential per site, assigned when the
 * invoice is created; UNIQUE(site_id, invoice_number) makes a duplicate number impossible.
 * An invoice stores no total: its total is the sum of its entries' amounts.
 *
 * `billable_entries` - the base table of a class-table-inheritance entity (rsx:man
 * detail_tables). type_id is the discriminator: TIME and EXPENSE each have a detail table
 * holding their own fields, a FIXED_FEE has none. `amount` is the entry's billable amount,
 * computed by the model from the detail (hours x rate, the expense cost) or entered for a
 * fixed fee. invoice_id is set while the entry is billed on an invoice.
 *
 * The detail tables are built by Rsx_Detail_Table::create(): a UNIQUE billable_entry_id with
 * ON DELETE CASCADE. They carry no site_id - an entry's detail is reached only through it.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE invoices (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                client_id BIGINT NOT NULL,
                invoice_number BIGINT NOT NULL,
                status BIGINT NOT NULL DEFAULT 1,
                issue_date DATE NULL DEFAULT NULL,
                due_date DATE NULL DEFAULT NULL,
                paid_date DATE NULL DEFAULT NULL,
                notes LONGTEXT NULL,
                deleted_at TIMESTAMP(3) NULL DEFAULT NULL,

                UNIQUE KEY uk_invoices_site_number (site_id, invoice_number),
                KEY idx_invoices_site_client (site_id, client_id),
                KEY idx_invoices_site_status (site_id, status),
                FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE billable_entries (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                type_id BIGINT NOT NULL,
                project_id BIGINT NOT NULL,
                task_id BIGINT NULL DEFAULT NULL,
                user_id BIGINT NOT NULL,
                entry_date DATE NOT NULL,
                description VARCHAR(255) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                status BIGINT NOT NULL DEFAULT 1,
                reviewed_by_user_id BIGINT NULL DEFAULT NULL,
                reviewed_at TIMESTAMP(3) NULL DEFAULT NULL,
                rejection_reason VARCHAR(1000) NULL DEFAULT NULL,
                invoice_id BIGINT NULL DEFAULT NULL,
                deleted_at TIMESTAMP(3) NULL DEFAULT NULL,

                KEY idx_billable_entries_site_status (site_id, status),
                KEY idx_billable_entries_site_project (site_id, project_id),
                KEY idx_billable_entries_invoice (invoice_id),
                FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // TIME detail: hours worked (not money) and the hourly rate (money) the amount is priced at.
        Rsx_Detail_Table::create('billable_entry_time_details', 'billable_entries', [
            ['name' => 'hours', 'type' => 'DECIMAL(8,2)', 'nullable' => false],
            ['name' => 'hourly_rate', 'type' => 'DECIMAL(12,2)', 'nullable' => false],
        ]);

        // EXPENSE detail: who was paid and what it cost.
        Rsx_Detail_Table::create('billable_entry_expense_details', 'billable_entries', [
            ['name' => 'vendor', 'type' => 'VARCHAR(255)'],
            ['name' => 'cost', 'type' => 'DECIMAL(12,2)', 'nullable' => false],
        ]);
    }
};
