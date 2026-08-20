<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Session\Session;
use Rsx\Models\Client_Model;

/**
 * Import the two committed sample documents (rsx/resource/sample_documents/) as File_Attachment
 * records so the template app ships working data for the Document Preview demo
 * (/dev/document_preview) and the search / text-extraction pipeline.
 *
 * OWNER-DIRECTED DEVIATION FROM THE NO-MODEL-REFS MIGRATION CONVENTION: RSpade migrations are
 * normally self-contained raw SQL with no Model/Service references. This template-app data-seed
 * migration deliberately uses File_Attachment_Model + Client_Model, per owner direction for the
 * Document Pipeline epic: importing binary documents THROUGH the attachment pipeline (content-
 * addressed dedup, blob storage, and the extraction-index kick fired by File_Storage_Model) cannot
 * be expressed in raw SQL. It lives in the template-app migration dir (not the framework) because
 * it depends on the app's Client_Model / clients table.
 *
 * Fails loud (RuntimeException) if the committed resource files are missing.
 */
return new class extends Migration
{
    public function up()
    {
        // base_path() is .../system; rsx/ is symlinked there -> the committed template resources.
        $dir = base_path('rsx/resource/sample_documents');
        $files = [
            ['path' => $dir . '/sample_report.pdf', 'name' => 'sample_report.pdf'],
            ['path' => $dir . '/sample_memo.docx', 'name' => 'sample_memo.docx'],
        ];

        foreach ($files as $f) {
            if (!file_exists($f['path'])) {
                throw new RuntimeException('Sample document missing (expected committed resource): ' . $f['path']);
            }
        }

        // Site: the first client's site, else the first sites row, else 0.
        $client = Client_Model::orderBy('id')->first();
        $site_id = $client
            ? (int) $client->site_id
            : (int) (DB::table('sites')->orderBy('id')->value('id') ?? 0);

        // Align the CLI session's site so add_to()'s claim guard passes: the guard requires the
        // attachment's site_id to equal the current site, and the attachment is created with the
        // same $site_id passed here.
        Session::set_site_id($site_id);

        foreach ($files as $f) {
            $attachment = File_Attachment_Model::create_from_disk($f['path'], [
                'site_id' => $site_id,
                'filename' => $f['name'],
            ]);

            // Attach to the first client when the template app has one; a framework-only install
            // (no clients) imports the documents unattached.
            if ($client) {
                $attachment->add_to($client, 'sample_documents');
            }
        }
    }

    /**
     * down() is prohibited in RSpade - forward-only migrations.
     */
};
