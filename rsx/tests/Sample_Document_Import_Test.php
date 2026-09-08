<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Post-migration state assertions for this application's import_sample_documents migration
 * (rsx/resource/migrations). Because the migration COMMITS imported attachment rows into the
 * baseline, this class requires a pristine migrated test DB (no surrounding transaction) so it
 * observes exactly what the migration produced.
 *
 * The migration attaches the two samples to the first client WHEN one exists, and imports them
 * unattached otherwise. The test-DB baseline is migrated but not seeded with client rows, so the
 * honest expectation here is the unattached branch - but the assertion is written against the
 * ACTUAL client-existence condition so it stays correct if a seeded baseline ever adds clients.
 */
class Sample_Document_Import_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    public static function test_sample_attachments_present()
    {
        $pdf = File_Attachment_Model::where('file_name', 'sample_report.pdf')->orderBy('id')->first();
        $docx = File_Attachment_Model::where('file_name', 'sample_memo.docx')->orderBy('id')->first();

        static::__assert_not_empty($pdf, 'sample_report.pdf attachment imported by the migration');
        static::__assert_not_empty($docx, 'sample_memo.docx attachment imported by the migration');

        static::__assert_equals('application/pdf', $pdf->mime_type, 'sample_report.pdf mime detected as application/pdf');
        static::__assert_equals(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $docx->mime_type,
            'sample_memo.docx mime detected as the OOXML docx type'
        );
    }

    public static function test_client_linkage_matches_client_existence()
    {
        // Resolve the same "first client" the migration used, from the table the migration
        // itself reads.
        $has_clients_table = static::__clients_table_exists();
        $first_client_row = $has_clients_table
            ? \Illuminate\Support\Facades\DB::selectOne('SELECT id FROM clients ORDER BY id LIMIT 1')
            : null;
        $first_client_id = $first_client_row->id ?? null;

        $pdf = File_Attachment_Model::where('file_name', 'sample_report.pdf')->orderBy('id')->first();
        static::__assert_not_empty($pdf, 'sample_report.pdf present for linkage assertion');

        if ($first_client_id === null) {
            // No client in the baseline -> the migration imports the samples unattached.
            static::__assert_null($pdf->fileable_type, 'no client in baseline -> sample_report.pdf is unattached');
            static::__assert_null($pdf->fileable_id, 'no client in baseline -> sample_report.pdf has no fileable_id');
            return;
        }

        // A client exists -> the samples are attached to the first client under 'sample_documents'.
        static::__assert_equals('Client_Model', $pdf->fileable_type, 'sample_report.pdf attached to a Client_Model');
        static::__assert_equals((int) $first_client_id, (int) $pdf->fileable_id, 'attached to the FIRST client (ordered by id)');
        static::__assert_equals('sample_documents', $pdf->fileable_category, 'attached under the sample_documents category');
    }

    /**
     * @return bool
     */
    private static function __clients_table_exists(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasTable('clients');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
