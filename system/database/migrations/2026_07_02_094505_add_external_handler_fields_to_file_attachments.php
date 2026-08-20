<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Attachment External Handlers (WP-A) - schema changes.
 *
 * Makes an attachment's bytes optionally originate from an external system (a handler)
 * instead of a resident local blob. _file_storage is untouched (still pure, resident,
 * content-addressed). Adds attachment-level authoritative metadata (mime_type, file_size)
 * that works without a resident blob, plus blob_accessed_at for app-side idle-eviction.
 *
 * Also rolls in the mime-type persistence fix: serving previously read a nonexistent
 * _file_storage.mime_type column. mime_type now lives on the attachment and is backfilled
 * from each resident blob here.
 */
return new class extends Migration
{
    public function up()
    {
        // file_storage_id becomes nullable. NULL is legal ONLY when handler_class is set
        // (enforced in the model layer, not by a DB constraint).
        DB::statement("ALTER TABLE _file_attachments MODIFY file_storage_id BIGINT NULL");

        // External-residency columns. handler_class is a simple (manifest-resolved) class name;
        // NULL = plain local attachment (100% of existing rows). handler_ref is opaque to the
        // framework - the handler owns its shape.
        DB::statement("ALTER TABLE _file_attachments ADD COLUMN handler_class VARCHAR(255) NULL AFTER file_storage_id");
        DB::statement("ALTER TABLE _file_attachments ADD COLUMN handler_ref JSON NULL AFTER handler_class");

        // Authoritative metadata that works without a resident blob.
        DB::statement("ALTER TABLE _file_attachments ADD COLUMN mime_type VARCHAR(255) NULL AFTER file_extension");
        DB::statement("ALTER TABLE _file_attachments ADD COLUMN file_size BIGINT NULL AFTER mime_type");

        // Touched (max once/day per row) whenever bytes are served/read. Enables app-side
        // idle-eviction policies.
        DB::statement("ALTER TABLE _file_attachments ADD COLUMN blob_accessed_at TIMESTAMP NULL");

        // Index for handler-based lookups (e.g. eviction sweeps by handler_class).
        DB::statement("CREATE INDEX idx_file_attachments_handler_class ON _file_attachments(handler_class)");

        // Backfill file_size for all existing rows from the resident blob's size.
        DB::statement("
            UPDATE _file_attachments a
            JOIN _file_storage s ON a.file_storage_id = s.id
            SET a.file_size = s.size
        ");

        // Backfill mime_type by re-detecting from the resident blob (one-time cost). Storage
        // path mirrors File_Storage_Model::get_storage_path() (hash-sharded). Self-contained:
        // no model references.
        $rows = DB::table('_file_attachments as a')
            ->join('_file_storage as s', 'a.file_storage_id', '=', 's.id')
            ->select('a.id', 's.hash')
            ->get();

        foreach ($rows as $row) {
            $hash = $row->hash;
            $dir1 = substr($hash, 0, 2);
            $dir2 = substr($hash, 2, 2);
            $full_path = storage_path("storage/files/{$dir1}/{$dir2}/{$hash}");

            $mime = null;
            if (file_exists($full_path)) {
                $detected = mime_content_type($full_path);
                if ($detected !== false) {
                    $mime = $detected;
                }
            }

            if ($mime !== null) {
                DB::table('_file_attachments')->where('id', $row->id)->update(['mime_type' => $mime]);
            }
        }
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
