<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Document text extraction - lifecycle columns on _search_indexes.
 *
 * The dormant _search_indexes table (0 rows, no writers before this feature) becomes the
 * store for extracted document text, keyed on the deduplicated File_Storage_Model blob so
 * identical bytes are extracted exactly once. Adds the extraction status lifecycle:
 *
 * - status_id: 1=EXTRACTED / 2=FAILED / 3=UNSUPPORTED. There is NO stored PENDING - the
 *   absence of a row (plus _file_storage.is_indexed=0) IS the queue. A row exists only AFTER
 *   an extraction attempt, so status_id is never absent; DEFAULT 1 covers the insert path.
 * - error: failure detail for FAILED rows (NULL otherwise).
 * - extractor_version: which pipeline generation produced this row, so a version bump can
 *   re-queue everything below the current version (rsx:search:reindex --below-version).
 *
 * F-1: Search_Index_Model re-parents from Rsx_Site_Model_Abstract to Rsx_Model_Abstract
 * because its subject (_file_storage) is a global, cross-site, content-addressed blob - a
 * per-site scope on the text index is meaningless and wrong. Site scoping is applied at the
 * _file_attachments join in File_Attachment_Model::search_text(), not here. site_id + its
 * index are dropped (safe: 0 rows, forward-only).
 */
return new class extends Migration
{
    public function up()
    {
        // Extraction lifecycle columns.
        DB::statement("ALTER TABLE _search_indexes ADD COLUMN status_id BIGINT NOT NULL DEFAULT 1 AFTER indexable_id");
        DB::statement("ALTER TABLE _search_indexes ADD COLUMN error TEXT NULL AFTER extraction_method");
        DB::statement("ALTER TABLE _search_indexes ADD COLUMN extractor_version BIGINT NOT NULL DEFAULT 1 AFTER error");

        // Indexes for status filtering and version-histogram / below-version reindex sweeps.
        DB::statement("CREATE INDEX idx_status ON _search_indexes(status_id)");
        DB::statement("CREATE INDEX idx_extractor_version ON _search_indexes(extractor_version)");

        // F-1: drop the now-meaningless per-site scope. _file_storage is global/cross-site;
        // site scoping happens at the _file_attachments join in search_text(). Drop the index
        // before the column it covers.
        DB::statement("DROP INDEX idx_site ON _search_indexes");
        DB::statement("ALTER TABLE _search_indexes DROP COLUMN site_id");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
