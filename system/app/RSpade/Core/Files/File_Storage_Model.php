<?php

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Search\Search_Index_Model;
use App\RSpade\Core\Task\Task;

/**
 * File_Storage_Model - Physical file storage with deduplication
 *
 * Represents unique physical files on disk. Multiple File_Attachment_Model records
 * can reference the same File_Storage_Model if they have identical content.
 *
 * Hash collision handling: When a hash collision is detected (same hash, different content),
 * the hash is incremented as a base-16 number until an available slot is found.
 */

/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _file_storage
 *
 * @property int $id
 * @property string $hash
 * @property int $size
 * @property bool $is_indexed
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
class File_Storage_Model extends Rsx_Model_Abstract
                     {
    // Required static properties from parent abstract class
    public static $enums = [];
    public static $rel = [];

    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per DISTINCT blob ever uploaded.
     *
     * Consumed by the DB-UNBOUNDED-01 code-quality rule, which flags a bare ->get() /
     * ->pluck() on this model in framework code and points at ->result_set(). It is a
     * DECLARATION, not a runtime gate - a small, well-narrowed query here is still fine.
     * See: the Do The Whole Job section of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;

    /**
     * The table associated with the model
     *
     * @var string
     */
    protected $table = '_file_storage';

    /**
     * The attributes that should be cast to native types
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Column metadata for special handling
     *
     * @var array
     */
    protected $columnMeta = [];

    /**
     * Cascade cleanup: extracted-text index rows key on this blob (indexable =
     * File_Storage_Model), so when the last-referencing attachment deletes the
     * storage row the index row must go with it - otherwise it lingers as
     * unreachable cruft (search_text() only ever joins live file_storage_ids).
     */
    protected static function boot()
    {
        parent::boot();

        static::deleted(function ($storage) {
            Search_Index_Model::forModel('File_Storage_Model', $storage->id)->delete();
        });
    }

    /**
     * Get all logical file attachments that reference this physical file
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function file_attachments()
    {
        return $this->hasMany(\App\RSpade\Core\Files\File_Attachment_Model::class, 'file_storage_id');
    }

    /**
     * Get the storage path for this file
     * Based on the hash for efficient file system distribution
     *
     * @return string
     */
    public function get_storage_path()
    {
        // RELATIVE display/back-compat path (e.g. "storage/files/ab/c1/abc123...").
        // The absolute on-disk path is get_full_path(), which routes through
        // Rsx_File_Paths so a test run relocates the blob store (B-38).
        return 'storage/files/' . static::__hash_subpath($this->hash);
    }

    /**
     * The hash-relative sub-path within the blob root: {dir1}/{dir2}/{hash}.
     * Splitting the hash into two levels keeps directories from growing unbounded.
     *
     * @param string $hash
     * @return string
     */
    protected static function __hash_subpath($hash)
    {
        $dir1 = substr($hash, 0, 2);
        $dir2 = substr($hash, 2, 2);

        return "{$dir1}/{$dir2}/{$hash}";
    }

    /**
     * Get the full file system path
     *
     * @return string
     */
    public function get_full_path()
    {
        // Resolve through the single choke point so a test run's storage-root
        // override relocates blob reads/writes off the shared store (B-38).
        return Rsx_File_Paths::blob_root() . '/' . static::__hash_subpath($this->hash);
    }

    /**
     * Human-readable byte size (e.g. "1.5 MB").
     *
     * @return string
     */
    public function get_human_size()
    {
        return bytes_to_human((int) $this->size);
    }

    /**
     * Check if the physical file exists on disk
     *
     * @return bool
     */
    public function file_exists()
    {
        return file_exists($this->get_full_path());
    }

    /**
     * Increment a hexadecimal hash value as a base-16 number
     *
     * Treats the entire hex string as a single base-16 number and increments it.
     * Example: "ff" becomes "100", "abc" becomes "abd"
     *
     * @param string $hash Hexadecimal hash string
     * @return string Incremented hash (may be longer than input)
     */
    public static function increment_base16_value($hash)
    {
        // Convert hex string to decimal
        $decimal = gmp_init($hash, 16);

        // Increment by 1
        $decimal = gmp_add($decimal, 1);

        // Convert back to hex
        $incremented = gmp_strval($decimal, 16);

        return $incremented;
    }

    /**
     * Store a temp file's bytes into the blob store and return its storage record.
     *
     * PINNED PUBLIC API (WP-A): this is the stable, documented name for the blob-ingest path.
     * Content-addressed (sha256), deduplicated, immutable. Delegates to find_or_create().
     * The framework guarantees this signature.
     *
     * @param string $temp_file_path Absolute path to a readable temp file.
     * @return static
     */
    public static function store_blob(string $temp_file_path): File_Storage_Model
    {
        return static::find_or_create($temp_file_path);
    }

    /**
     * Find or create a file storage record with collision handling
     *
     * Algorithm:
     * 1. Hash the uploaded file (SHA-256)
     * 2. Check if file exists on disk at hash path
     * 3. If exists: byte-by-byte compare with uploaded file
     *    - Match: return existing record
     *    - No match: increment hash, repeat from step 2
     * 4. If doesn't exist: save file to disk, create record
     *
     * @param string $temp_file_path Path to uploaded temporary file
     * @return static
     */
    public static function find_or_create($temp_file_path)
    {
        if (!file_exists($temp_file_path)) {
            shouldnt_happen("Temporary file does not exist: {$temp_file_path}");
        }

        $original_hash = hash_file('sha256', $temp_file_path);
        $current_hash = $original_hash;
        $file_size = filesize($temp_file_path);

        // Loop until we find a matching file or an available slot
        while (true) {
            // Check if a record exists for this hash
            $existing_record = static::where('hash', $current_hash)->first();

            if ($existing_record) {
                // Record exists - check if physical file exists
                if ($existing_record->file_exists()) {
                    // Byte-by-byte compare
                    $existing_path = $existing_record->get_full_path();

                    if (static::__files_match($temp_file_path, $existing_path)) {
                        // Perfect match - reuse existing storage
                        return $existing_record;
                    } else {
                        // Hash collision - increment and try again
                        $current_hash = static::increment_base16_value($current_hash);
                        continue;
                    }
                } else {
                    // Record exists but file is missing - should not happen
                    // Reuse this hash slot
                    break;
                }
            } else {
                // No record for this hash - available slot
                break;
            }
        }

        // Create new storage record and save file. Resolve through the choke point
        // so a test run writes into its isolated storage root (B-38).
        $full_path = Rsx_File_Paths::blob_root() . '/' . static::__hash_subpath($current_hash);

        // Create directory if needed
        $directory = dirname($full_path);
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        // Copy file to storage
        copy($temp_file_path, $full_path);

        // Create record
        $file_storage = new static();
        $file_storage->hash = $current_hash;
        $file_storage->size = $file_size;
        $file_storage->save();

        // Prompt kick for document text extraction. This is the single choke point for a NEW
        // unique blob entering the store (covers upload, create_from_*, external
        // materialization); a dedup hit returns earlier and never reaches here. The blob starts
        // is_indexed=0, so even without this kick the 5-minute cron would eventually pick it up;
        // the dispatch just drains promptly. #[Exclusive] coalesces concurrent kicks.
        if (config('rsx.search.enabled', true)) {
            Task::dispatch('Search_Index_Service', 'index_pending');
        }

        return $file_storage;
    }

    /**
     * Get the extracted-text search index row for this blob, or null if it has not been indexed
     * yet (no attempt recorded). Absence of a row + is_indexed=0 means it is still queued.
     *
     * @return Search_Index_Model|null
     */
    public function get_search_index(): ?Search_Index_Model
    {
        return Search_Index_Model::forModel('File_Storage_Model', $this->id)->first();
    }

    /**
     * Compare two files byte-by-byte
     *
     * @param string $file1_path
     * @param string $file2_path
     * @return bool True if files are identical
     */
    protected static function __files_match($file1_path, $file2_path)
    {
        // Quick size check first
        if (filesize($file1_path) !== filesize($file2_path)) {
            return false;
        }

        // Byte-by-byte comparison
        $fp1 = fopen($file1_path, 'rb');
        $fp2 = fopen($file2_path, 'rb');

        $match = true;
        while (!feof($fp1) && !feof($fp2)) {
            if (fread($fp1, 8192) !== fread($fp2, 8192)) {
                $match = false;
                break;
            }
        }

        fclose($fp1);
        fclose($fp2);

        return $match;
    }


    /**
     * Calculate hash for file content
     *
     * @param string $content
     * @return string
     */
    public static function calculate_hash($content)
    {
        return hash('sha256', $content);
    }

    /**
     * Calculate hash for a file path
     *
     * @param string $file_path
     * @return string|false
     */
    public static function calculate_file_hash($file_path)
    {
        if (!file_exists($file_path)) {
            return false;
        }

        return hash_file('sha256', $file_path);
    }
}
