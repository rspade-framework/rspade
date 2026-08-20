<?php

namespace App\RSpade\Core\Files;

use Exception;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Imagick;
use ImagickException;
use Throwable;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Files\File_Attachment_Icons;
use App\RSpade\Core\Files\File_Disposal_Service;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Unparseable_Upload_Exception;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Search\Search_Index_Model;
use App\RSpade\Core\Time\Rsx_Time;
/**
 * File_Attachment_Model - Logical file upload record
 *
 * Represents a file upload event with metadata. Multiple attachments can reference
 * the same File_Storage_Model if they have identical content (deduplication).
 *
 * Files are site-scoped and can be attached to any model via polymorphic relationships.
 *
 * file_type_id is an enum indicating the type of file:
 * 1 = image (jpg, png, gif, etc.)
 * 2 = animated_image (gif, webp with animation)
 * 3 = video (mp4, webm, avi, etc.)
 * 4 = archive (zip, tar, rar, etc.)
 * 5 = text (txt, md, etc.)
 * 6 = document (pdf, doc, xls, etc.)
 * 7 = other (anything else)
 *
 * The framework will add more sophisticated enum support later, but this
 * provides the basic structure for categorizing uploaded files.
 */


/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _file_attachments
 *
 * @property int $id
 * @property string $key
 * @property int $file_storage_id
 * @property string $handler_class
 * @property array $handler_ref
 * @property string $file_name
 * @property string $file_extension
 * @property string $mime_type
 * @property int $file_size
 * @property int $file_type_id
 * @property int $width
 * @property int $height
 * @property int $duration
 * @property bool $is_animated
 * @property int $frame_count
 * @property bool $preview_unavailable
 * @property int $fileable_type
 * @property int $fileable_id
 * @property string $fileable_category
 * @property string $fileable_type_meta
 * @property int $fileable_order
 * @property string $fileable_meta
 * @property int $site_id
 * @property string $created_by_ip_address
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property string $blob_accessed_at
 * @property string $deleted_at
 * @property string $destroyed_at
 * @property int $deleted_by_id
 * @property int $deleted_by_type
 *
 * @property-read string $file_type_id__label
 * @property-read string $file_type_id__constant
 *
 * @method static array file_type_id__enum() Get all enum definitions with full metadata
 * @method static array file_type_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array file_type_id__enum_labels() Get simple id => label map
 * @method static array file_type_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
class File_Attachment_Model extends Rsx_Site_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const FILE_TYPE_IMAGE = 1;
    const FILE_TYPE_ANIMATED_IMAGE = 2;
    const FILE_TYPE_VIDEO = 3;
    const FILE_TYPE_ARCHIVE = 4;
    const FILE_TYPE_TEXT = 5;
    const FILE_TYPE_DOCUMENT = 6;
    const FILE_TYPE_OTHER = 7;

    /**
     * Retention lifecycle. An attachment is SOFT-DELETED (deleted_at, recoverable) and only
     * becomes a permanent tombstone (destroyed_at) after the retention window elapses. Blob
     * release is the disposal task's EXCLUSIVE job (File_Disposal_Service) - there is no
     * inline "deleting the last attachment unlinks the blob" hook any more, because a
     * soft-deleted-but-not-destroyed attachment STILL pins its blob (retention-aware
     * refcount). delete() therefore means "enter retention"; force_destroy() is the
     * immediate out-of-band erasure.
     */
    use SoftDeletes;

    /**
     * Enum field definitions
     * @var array
     */
    public static $enums = [
        'file_type_id' => [
            1 => [
                'constant' => 'FILE_TYPE_IMAGE',
                'label' => 'Image',
            ],
            2 => [
                'constant' => 'FILE_TYPE_ANIMATED_IMAGE',
                'label' => 'Animated Image',
            ],
            3 => [
                'constant' => 'FILE_TYPE_VIDEO',
                'label' => 'Video',
            ],
            4 => [
                'constant' => 'FILE_TYPE_ARCHIVE',
                'label' => 'Archive',
            ],
            5 => [
                'constant' => 'FILE_TYPE_TEXT',
                'label' => 'Text',
            ],
            6 => [
                'constant' => 'FILE_TYPE_DOCUMENT',
                'label' => 'Document',
            ],
            7 => [
                'constant' => 'FILE_TYPE_OTHER',
                'label' => 'Other',
            ],
        ],
    ];

    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per upload, forever - plus retained tombstones.
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
    protected $table = '_file_attachments';

    /**
     * Attribute casts.
     *
     * handler_ref is an opaque JSON blob owned by the attachment's external handler; the
     * framework never reads or writes its contents (WP-A).
     *
     * @var array
     */
    protected $casts = [
        'handler_ref' => 'array',
    ];

    /**
     * Polymorphic type reference columns
     *
     * fileable_type stores integer ID in database but exposes class name string
     * @var array
     */
    protected static $type_ref_columns = ['fileable_type'];

    /**
     * Get the physical file storage record
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    #[Relationship]
    public function file_storage()
    {
        return $this->belongsTo(File_Storage_Model::class, 'file_storage_id');
    }

    /**
     * Get the site this file belongs to
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    #[Relationship]
    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    /**
     * Get the extracted document text for this attachment, or null if it has not been indexed
     * yet. Routes through resolve_storage() (materializes external bytes if needed) so the text
     * is keyed on the deduplicated blob - every attachment pointing at the same content shares
     * one extraction. Returns '' for a blob that was indexed but yielded no text (FAILED /
     * UNSUPPORTED, or a text-free document); use get_extraction_status() to distinguish.
     *
     * @return string|null
     */
    public function get_extracted_text(): ?string
    {
        $storage = $this->resolve_storage();
        $index = $storage->get_search_index();

        return $index ? $index->content : null;
    }

    /**
     * Get the extraction status_id for this attachment WITHOUT materializing external bytes.
     * Reads file_storage_id directly: null (no resident blob, or no index row yet) means the
     * document is still pending extraction. A non-null value is one of
     * Search_Index_Model::STATUS_EXTRACTED / STATUS_FAILED / STATUS_UNSUPPORTED.
     *
     * @return int|null status_id, or null if pending / not yet indexed.
     */
    public function get_extraction_status(): ?int
    {
        if ($this->file_storage_id === null) {
            return null;
        }

        $index = Search_Index_Model::forModel('File_Storage_Model', $this->file_storage_id)->first();

        return $index ? $index->status_id : null;
    }

    /**
     * Resolve "attachments whose extracted text matches $query" as an Eloquent Builder the app
     * can compose into its own queries (add site scoping, joins, further filters, ordering).
     *
     * Runs a FULLTEXT MATCH...AGAINST over _search_indexes (File_Storage_Model rows only,
     * EXTRACTED only), then returns a File_Attachment_Model builder constrained to the matching
     * deduplicated blobs. Note dedup: one matching blob may back several attachments, so the
     * builder can yield multiple rows per storage - exactly what the app wants for a Files index.
     *
     * @param string $query Full-text query.
     * @param string $mode 'BOOLEAN' or 'NATURAL LANGUAGE'.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function search_text(string $query, string $mode = 'BOOLEAN')
    {
        // The matching blob ids stay in SQL as a sub-select. Plucking them into PHP
        // first would materialize the entire match set - a broad query over a large
        // index is a six-figure row count - and then round-trip it back as a literal
        // IN list. The caller receives a builder either way.
        $storage_ids = Search_Index_Model::search($query, $mode)
            ->where('indexable_type', 'File_Storage_Model')
            ->where('status_id', Search_Index_Model::STATUS_EXTRACTED)
            ->select('indexable_id');

        return static::whereIn('file_storage_id', $storage_ids);
    }

    /**
     * Check if this is an image file
     *
     * @return bool
     */
    public function is_image()
    {
        return in_array($this->file_type_id, [self::FILE_TYPE_IMAGE, self::FILE_TYPE_ANIMATED_IMAGE]);
    }

    /**
     * Check if this is a video file
     *
     * @return bool
     */
    public function is_video()
    {
        return $this->file_type_id == self::FILE_TYPE_VIDEO;
    }

    /**
     * Check if this is a document file
     *
     * @return bool
     */
    public function is_document()
    {
        return $this->file_type_id == self::FILE_TYPE_DOCUMENT;
    }

    /**
     * Whether this attachment can be previewed. False once it has been degraded (an unparseable
     * image accepted as a generic, non-previewable file). Inverse of the preview_unavailable flag.
     *
     * @return bool
     */
    public function is_preview_available(): bool
    {
        return !$this->preview_unavailable;
    }

    /**
     * Developer-callable extension allowlist check (PROVIDE ONLY - the framework does NOT auto-
     * enforce this at /_upload). Reads config('rsx.attachments.allowed_extensions'): an EMPTY
     * list means allow ALL extensions. Comparison is case-insensitive and tolerates a leading dot
     * on either the argument or a configured entry.
     *
     * @param string $ext File extension, with or without a leading dot (e.g. 'png' or '.PNG').
     * @return bool
     */
    public static function is_allowed_extension(string $ext): bool
    {
        $allowed = config('rsx.attachments.allowed_extensions', []);

        // Empty allowlist = allow everything.
        if (empty($allowed)) {
            return true;
        }

        $normalize = fn (string $e): string => strtolower(ltrim(trim($e), '.'));

        $needle = $normalize($ext);
        foreach ($allowed as $entry) {
            if ($normalize((string) $entry) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Instance form of is_allowed_extension() over THIS attachment's stored file_extension.
     *
     * @return bool
     */
    public function has_allowed_extension(): bool
    {
        return static::is_allowed_extension((string) $this->file_extension);
    }

    /**
     * Get the inline viewing URL for this file
     *
     * @return string
     */
    public function get_url()
    {
        return "/_inline/{$this->key}";
    }

    /**
     * Get the download URL for this file
     *
     * @return string Relative URL
     */
    public function get_download_url()
    {
        return "/_download/{$this->key}";
    }

    /**
     * Get thumbnail URL for this file (dynamic thumbnails)
     *
     * @param string $type   Thumbnail type: 'cover' or 'fit'
     * @param int    $width  Thumbnail width in pixels
     * @param int    $height Optional thumbnail height in pixels
     * @return string Relative URL
     */
    public function get_thumbnail_url($type = 'fit', $width = 400, $height = null)
    {
        if ($height === null) {
            return "/_thumbnail/dynamic/{$this->key}/{$type}/{$width}";
        }
        return "/_thumbnail/dynamic/{$this->key}/{$type}/{$width}/{$height}";
    }

    /**
     * Get thumbnail URL for named preset
     *
     * Returns URL to preset thumbnail endpoint. Preset must be defined in
     * config/rsx.php under 'thumbnails.presets'.
     *
     * @param string $preset_name Preset name from config (e.g., 'profile', 'gallery')
     * @return string Relative URL
     * @throws Exception if preset not defined
     */
    public function get_thumbnail_url_preset($preset_name)
    {
        $presets = config('rsx.thumbnails.presets', []);

        if (!isset($presets[$preset_name])) {
            throw new Exception("Thumbnail preset '{$preset_name}' not defined in config");
        }

        return "/_thumbnail/preset/{$this->key}/{$preset_name}";
    }

    /**
     * Get icon resource path for this file type
     *
     * Returns the relative path to the most appropriate icon file based on the
     * file extension. Icons are stored in system/app/RSpade/Core/Files/resource/icons/
     *
     * Brand-specific icons (PDF, Photoshop, Illustrator) are PNG files with brand colors.
     * Generic category icons (image, video, audio, etc.) are SVG files.
     *
     * @return string Relative path to icon file from project root
     */
    public function get_icon_resource()
    {
        return File_Attachment_Icons::get_icon_resource_by_file_extension($this->file_extension);
    }

    /**
     * Get file size in bytes
     *
     * Reads the authoritative attachment-level file_size column, which is populated at every
     * ingest and backfilled for existing rows. Works WITHOUT a resident blob (external
     * attachments) - never touches storage.
     *
     * @return int
     */
    public function get_size()
    {
        return (int) $this->file_size;
    }

    /**
     * Human-readable byte size (e.g. "1.5 MB"). Works without a resident blob.
     *
     * @return string
     */
    public function get_human_size()
    {
        return bytes_to_human($this->get_size());
    }

    /**
     * Whether this attachment currently has a resident local blob.
     *
     * @return bool
     */
    public function has_blob(): bool
    {
        return $this->file_storage_id !== null;
    }

    /**
     * Whether the thumbnail pipeline can render a real preview for this attachment's mime type
     * (i.e. a renderer is registered in config('rsx.thumbnails.renderers')). image/* returns
     * true. When false, thumbnails use a generic extension icon.
     *
     * @return bool
     */
    public function has_thumbnail(): bool
    {
        return File_Attachment_Controller::renderer_class_for_mime($this->pipeline_mime()) !== null;
    }

    // ============================================================================================
    // BYTE RESIDENCY (WP-A) - external handlers, materialization, eviction
    // ============================================================================================

    /**
     * THE byte-access choke point. Returns the storage row, materializing first if
     * file_storage_id is null (dispatching fetch() on handler_class). Touches blob_accessed_at.
     * Throws if there is neither a resident blob nor a handler.
     *
     * Every framework operation that needs attachment bytes routes through this method (WP-A
     * compatibility guarantee).
     *
     * @return File_Storage_Model
     */
    public function resolve_storage(): File_Storage_Model
    {
        if ($this->file_storage_id === null) {
            if ($this->handler_class === null) {
                shouldnt_happen("Attachment {$this->id} has no resident blob and no handler_class");
            }
            $this->materialize();
        }

        $storage = File_Storage_Model::find($this->file_storage_id);
        if (!$storage) {
            shouldnt_happen("Attachment {$this->id} references missing storage {$this->file_storage_id}");
        }

        $this->__touch_blob_accessed_at();

        return $storage;
    }

    /**
     * Force materialization of external bytes into the local blob store. No-op if already
     * resident. Dispatches the handler's fetch(), stores the bytes via store_blob(), and links
     * them via relink_storage(). Handler byte-fetch failures bubble (fail loud).
     *
     * @return void
     */
    public function materialize(): void
    {
        if ($this->file_storage_id !== null) {
            return;
        }

        $handler_fqcn = $this->__resolve_handler();

        $temp_path = $handler_fqcn::fetch($this);

        try {
            $storage = File_Storage_Model::store_blob($temp_path);
            $this->relink_storage($storage);
        } finally {
            if (is_string($temp_path) && file_exists($temp_path)) {
                @unlink($temp_path);
            }
        }
    }

    /**
     * Repoint this attachment at $storage: set file_storage_id + file_size, re-derive mime_type
     * and file_type_id + dimensions from the new bytes, and save. Does NOT touch
     * handler_class/handler_ref. Any previously-linked blob becomes garbage for the orphan
     * sweep if unreferenced.
     *
     * Thumbnail cache self-invalidates: cache filenames derive from file_storage->hash, so
     * repointing to different content produces different cache keys automatically - no cache
     * clearing needed.
     *
     * @param File_Storage_Model $storage
     * @return void
     */
    public function relink_storage(File_Storage_Model $storage): void
    {
        $this->file_storage_id = $storage->id;
        $this->file_size = $storage->size;

        // Re-derive mime + coarse file type from the new bytes (authoritative).
        $full_path = $storage->get_full_path();
        if (file_exists($full_path)) {
            $mime = mime_content_type($full_path);
            if ($mime !== false) {
                // Store the raw sniff (serving); bucket file_type_id on the pipeline mime
                // (extension-first for documents).
                $this->mime_type = $mime;
                $this->file_type_id = static::determine_file_type(
                    static::resolve_pipeline_mime($mime, $this->file_extension)
                );
            }
        }

        $this->save();

        // Re-extract dimensions / animation (may upgrade image -> animated_image). Saves if dirty.
        $this->process_file();
    }

    /**
     * Null file_storage_id so the local blob may be swept by the orphan cleanup. Throws unless
     * handler_class is set (bytes must remain recoverable). Physical disk cleanup remains the
     * orphan sweep's job; the previously-linked blob is left for it.
     *
     * @return void
     * @throws \App\RSpade\Core\Debug\Rsx_Caller_Exception
     */
    public function evict_blob(): void
    {
        if ($this->handler_class === null) {
            throw new \App\RSpade\Core\Debug\Rsx_Caller_Exception(
                'Cannot evict blob: attachment has no handler_class (bytes would be unrecoverable)'
            );
        }

        if ($this->file_storage_id === null) {
            return;
        }

        $this->file_storage_id = null;
        $this->save();
    }

    /**
     * Factory for externally-sourced attachments (no upload, no session). Sets
     * handler_class/handler_ref, generates a key, derives file_type_id from mime_type, and
     * leaves file_storage_id null (bytes materialize on first demand). Returns UNATTACHED - the
     * caller assigns it with the existing attach_to()/add_to().
     *
     * @param string $handler_class Simple class name; MUST be registered in
     *                              config('rsx.attachments.handlers').
     * @param array  $handler_ref   Opaque handler-owned reference (persisted as JSON).
     * @param array  $attributes    Required: site_id, file_name, file_extension, mime_type,
     *                              file_size. Optional: notes.
     * @return static
     * @throws \App\RSpade\Core\Debug\Rsx_Caller_Exception
     */
    public static function create_external(string $handler_class, array $handler_ref, array $attributes): static
    {
        foreach (['site_id', 'file_name', 'file_extension', 'mime_type', 'file_size'] as $required) {
            if (!isset($attributes[$required])) {
                throw new \App\RSpade\Core\Debug\Rsx_Caller_Exception(
                    "create_external: missing required attribute '{$required}'"
                );
            }
        }

        // Security: only registered handlers may be attached.
        $allowlist = config('rsx.attachments.handlers', []);
        if (!in_array($handler_class, $allowlist, true)) {
            shouldnt_happen(
                "create_external: handler_class '{$handler_class}' is not registered in config('rsx.attachments.handlers')"
            );
        }

        $extension = strtolower($attributes['file_extension']);
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $attachment = new static();
        $attachment->key = static::generate_key();
        $attachment->handler_class = $handler_class;
        $attachment->handler_ref = $handler_ref;
        $attachment->file_storage_id = null;
        $attachment->file_name = $attributes['file_name'];
        $attachment->file_extension = $extension;
        $attachment->mime_type = $attributes['mime_type'];
        $attachment->file_size = $attributes['file_size'];
        // Bucket on the pipeline mime (extension-first for documents); a caller-supplied generic
        // sniff on a document extension still buckets as DOCUMENT.
        $attachment->file_type_id = static::determine_file_type(
            static::resolve_pipeline_mime($attributes['mime_type'], $extension)
        );
        $attachment->site_id = $attributes['site_id'];
        // Audit stamp, request-context only. Left UNSET (not explicitly null) when there is no
        // client IP: the column defaults NULL, and an unset attribute keeps the INSERT column
        // list to what the caller actually has - which is what lets the CLI/model-based data
        // seeds in migration history run against a schema that predates this column.
        $ip_address = static::__creation_ip_address();
        if ($ip_address !== null) {
            $attachment->created_by_ip_address = $ip_address;
        }

        if (isset($attributes['notes'])) {
            $attachment->set_meta(['notes' => $attributes['notes']]);
        }

        $attachment->save();

        return $attachment;
    }

    /**
     * Resolve and authorize this attachment's handler class. Enforces the security allowlist:
     * a handler_class not present in config('rsx.attachments.handlers') is a hard error - the
     * framework NEVER instantiates a class name read from the database unless it is registered.
     *
     * @return string Fully-qualified handler class name (a Rsx_Attachment_Handler_Abstract).
     */
    protected function __resolve_handler(): string
    {
        $simple_name = $this->handler_class;

        $allowlist = config('rsx.attachments.handlers', []);
        if (!in_array($simple_name, $allowlist, true)) {
            shouldnt_happen(
                "Attachment handler_class '{$simple_name}' is not registered in config('rsx.attachments.handlers')"
            );
        }

        $metadata = \App\RSpade\Core\Manifest\Manifest::php_get_metadata_by_class($simple_name);
        $fqcn = $metadata['fqcn'] ?? null;
        if ($fqcn === null) {
            shouldnt_happen("Attachment handler_class '{$simple_name}' not found in manifest");
        }

        return $fqcn;
    }

    /**
     * Touch blob_accessed_at at most once per day per row (avoids write amplification on hot
     * serve paths). Uses a raw update so it does not bump updated_at / audit columns.
     *
     * @return void
     */
    protected function __touch_blob_accessed_at(): void
    {
        $last = $this->blob_accessed_at;
        if ($last !== null && \App\RSpade\Core\Time\Rsx_Time::seconds_since($last) < 86400) {
            return;
        }

        $now_iso = \App\RSpade\Core\Time\Rsx_Time::now_iso();
        $this->blob_accessed_at = $now_iso;

        // Raw targeted update: touches only blob_accessed_at, deliberately NOT bumping updated_at
        // or audit columns on a read path. Table name is a model constant (not user input).
        \Illuminate\Support\Facades\DB::update(
            'UPDATE ' . $this->getTable() . ' SET blob_accessed_at = ? WHERE id = ?',
            [\App\RSpade\Core\Time\Rsx_Time::to_database($now_iso), $this->id]
        );
    }

    /**
     * Whether this attachment is backed by an external handler (vs a plain local blob).
     *
     * @return bool
     */
    public function has_handler(): bool
    {
        return $this->handler_class !== null;
    }

    /**
     * Serve-time freshness hook. If this attachment's handler opts in
     * (CHECK_FRESHNESS_ON_SERVE) and reports the remote content stale, evict the local blob so
     * the next resolve_storage() re-materializes fresh bytes. No-op for plain attachments and
     * handlers that do not opt in. Called by the serve endpoints AFTER auth gates.
     *
     * @return void
     */
    public function apply_serve_freshness(): void
    {
        if ($this->handler_class === null) {
            return;
        }

        $handler_fqcn = $this->__resolve_handler();

        if ($handler_fqcn::CHECK_FRESHNESS_ON_SERVE && $handler_fqcn::is_stale($this)) {
            $this->evict_blob();
        }
    }

    /**
     * Give the handler a chance to fully take over a download response (streaming proxy or a 302
     * to a pre-authorized URL). Returns null for plain attachments or when the handler declines
     * (framework then materializes-and-serves normally).
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response|null
     */
    public function handler_serve_download($request)
    {
        if ($this->handler_class === null) {
            return null;
        }

        $handler_fqcn = $this->__resolve_handler();

        return $handler_fqcn::serve_download($this, $request);
    }

    /**
     * Inline counterpart of handler_serve_download().
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response|null
     */
    public function handler_serve_inline($request)
    {
        if ($this->handler_class === null) {
            return null;
        }

        $handler_fqcn = $this->__resolve_handler();

        return $handler_fqcn::serve_inline($this, $request);
    }

    /**
     * Generate thumbnail cache hash
     *
     * Creates a composite hash for thumbnail caching that combines:
     * 1. The storage hash (identifies the source file)
     * 2. An underscore separator
     * 3. MD5 of (JSON-encoded parameters + lowercase file extension)
     *
     * This allows thumbnails to be quickly associated with their source file
     * while maintaining unique identifiers for different transformation parameters.
     *
     * @param array $data Thumbnail parameters (e.g., ['type' => 'cover', 'width' => 96, 'height' => 96])
     * @return string Format: {storage_hash}_{md5_of_params_and_extension}
     *
     * @example
     *   $hash = $attachment->generate_thumbnail_hash(['type' => 'cover', 'width' => 96, 'height' => 96]);
     *   // Returns: "2e29065e...8cffdc1b_a1b2c3d4e5f6..."
     */
    public function generate_thumbnail_hash(array $data)
    {
        // Get the storage hash (SHA-256 of physical file)
        $storage_hash = $this->file_storage->hash;

        // Get lowercase file extension
        $extension = strtolower($this->file_extension);

        // Build the data string: JSON + extension
        $data_string = json_encode($data) . $extension;

        // Calculate MD5 hash of the data string
        $calculated_hash = md5($data_string);

        // Combine: storage_hash_calculated_hash
        return $storage_hash . '_' . $calculated_hash;
    }

    /**
     * Generate a unique key for a new file attachment
     *
     * Uses cryptographically secure random 64-character hex string
     *
     * @return string
     */
    public static function generate_key()
    {
        do {
            $key = bin2hex(random_bytes(32));
        } while (static::where('key', $key)->exists());

        return $key;
    }

    /**
     * Find a file attachment by its key
     *
     * @param string $key
     * @return static|null
     */
    public static function find_by_key($key)
    {
        return static::where('key', $key)->first();
    }

    /**
     * Create file attachment from uploaded file
     *
     * This is the primary method for handling file uploads from HTTP requests.
     * Automatically handles storage creation, deduplication, and metadata.
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param array $params Optional parameters:
     *   - site_id: (required) Site ID
     *   - fileable_type: Model class name to attach to
     *   - fileable_id: Model ID to attach to
     *   - fileable_category: Category (e.g., 'avatar', 'document')
     *   - fileable_type_meta: Searchable metadata (e.g., 'profile', 'cover')
     *   - fileable_meta: Array of additional metadata
     *   - fileable_order: Sort order
     *   - filename_override: Override the original filename
     * @return static
     * @throws Exception
     */
    public static function create_from_upload($file, array $params = [])
    {
        if (!isset($params['site_id'])) {
            throw new Exception('site_id is required in params');
        }

        // Create or find storage
        $storage = File_Storage_Model::find_or_create($file->getRealPath());

        // Determine filename
        $filename = $params['filename_override'] ?? $file->getClientOriginalName();
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        // Normalize extension: lowercase and jpeg -> jpg
        $extension = strtolower($extension);
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        // Detect MIME type (raw byte sniff - persisted verbatim + used for serving).
        $mime_type = $file->getMimeType();

        // Coarse file_type_id buckets on the PIPELINE mime (extension-first for documents), so a
        // zip-sniffed OOXML doc buckets as DOCUMENT, not ARCHIVE.
        $file_type_id = static::determine_file_type(static::resolve_pipeline_mime($mime_type, $extension));

        // Create attachment
        $attachment = new static();
        $attachment->key = static::generate_key();
        $attachment->file_storage_id = $storage->id;
        $attachment->file_name = $filename;
        $attachment->file_extension = $extension;
        $attachment->mime_type = $mime_type;
        $attachment->file_size = $storage->size;
        $attachment->file_type_id = $file_type_id;
        $attachment->site_id = $params['site_id'];
        // Audit stamp, request-context only. Left UNSET (not explicitly null) when there is no
        // client IP: the column defaults NULL, and an unset attribute keeps the INSERT column
        // list to what the caller actually has - which is what lets the CLI/model-based data
        // seeds in migration history run against a schema that predates this column.
        $ip_address = static::__creation_ip_address();
        if ($ip_address !== null) {
            $attachment->created_by_ip_address = $ip_address;
        }

        // Apply optional parameters
        if (isset($params['fileable_type'])) {
            $attachment->fileable_type = $params['fileable_type'];
        }
        if (isset($params['fileable_id'])) {
            $attachment->fileable_id = $params['fileable_id'];
        }
        if (isset($params['fileable_category'])) {
            $attachment->fileable_category = $params['fileable_category'];
        }
        if (isset($params['fileable_type_meta'])) {
            $attachment->fileable_type_meta = $params['fileable_type_meta'];
        }
        if (isset($params['fileable_order'])) {
            $attachment->fileable_order = $params['fileable_order'];
        }
        if (isset($params['fileable_meta'])) {
            $attachment->set_meta($params['fileable_meta']);
        }

        $attachment->save();

        // Extract metadata using ImageMagick. In strict reject-mode a content-parse failure throws
        // Unparseable_Upload_Exception - but create_from_upload() is NOT transactional (the row +
        // blob are already persisted above), so clean up the orphan before re-throwing to the
        // endpoint (which turns it into a 4xx). force_destroy() (NOT delete()) because a plain
        // delete() now only SOFT-deletes into the retention window - a rejected upload must be
        // erased immediately, blob included, never left to linger for 30 days.
        try {
            $attachment->process_file();
        } catch (Unparseable_Upload_Exception $e) {
            $attachment->force_destroy();
            throw $e;
        }

        return $attachment;
    }

    /**
     * Create file attachment from disk path
     *
     * Useful for importing files from local filesystem or processing uploaded
     * files that have been moved to a temporary location.
     *
     * @param string $path Absolute path to file on disk
     * @param array $params Same as create_from_upload() plus:
     *   - filename: (required if not in params) Filename to use
     * @return static
     * @throws Exception
     */
    public static function create_from_disk($path, array $params = [])
    {
        if (!file_exists($path)) {
            throw new Exception("File does not exist: {$path}");
        }

        if (!isset($params['site_id'])) {
            throw new Exception('site_id is required in params');
        }

        // Create temp UploadedFile to reuse existing logic
        $filename = $params['filename'] ?? basename($path);
        $mime_type = mime_content_type($path);

        // Create temporary copy to avoid moving the source file
        $temp_dir = sys_get_temp_dir();
        $temp_name = 'rspade_upload_' . uniqid() . '_' . basename($path);
        $temp_path = $temp_dir . '/' . $temp_name;

        if (!copy($path, $temp_path)) {
            throw new Exception('Failed to copy file to temporary location');
        }

        try {
            // Create UploadedFile instance
            // Laravel's UploadedFile extends Symfony's UploadedFile
            // Parameters: path, originalName, mimeType, error, test
            $uploaded_file = new \Illuminate\Http\UploadedFile(
                $temp_path,
                $filename,
                $mime_type,
                null,
                true // Mark as test to avoid "file not uploaded" errors
            );

            $attachment = static::create_from_upload($uploaded_file, $params);

            return $attachment;
        } finally {
            // Clean up temp file
            if (file_exists($temp_path)) {
                @unlink($temp_path);
            }
        }
    }

    /**
     * Create file attachment from string content
     *
     * Useful for generating files from code (e.g., exports, reports, rendered images).
     *
     * @param string $content File content as string
     * @param string $filename Filename (must include extension)
     * @param array $params Same as create_from_upload()
     * @return static
     * @throws Exception
     */
    public static function create_from_string($content, $filename, array $params = [])
    {
        if (!isset($params['site_id'])) {
            throw new Exception('site_id is required in params');
        }

        // Create temporary file
        $temp_dir = sys_get_temp_dir();
        $temp_name = 'rspade_upload_' . uniqid() . '_' . $filename;
        $temp_path = $temp_dir . '/' . $temp_name;

        if (file_put_contents_safe($temp_path, $content) === false) {
            throw new Exception('Failed to write content to temporary file');
        }

        try {
            $attachment = static::create_from_disk($temp_path, array_merge($params, [
                'filename' => $filename,
            ]));

            return $attachment;
        } finally {
            // Clean up temp file
            if (file_exists($temp_path)) {
                @unlink($temp_path);
            }
        }
    }

    /**
     * Create file attachment from URL
     *
     * Downloads file from URL and creates attachment. Useful for importing
     * files from external sources.
     *
     * @param string $url URL to download file from
     * @param array $params Same as create_from_upload() plus:
     *   - filename: Filename to use (defaults to basename of URL)
     *   - timeout: Download timeout in seconds (default: 30)
     * @return static
     * @throws Exception
     */
    public static function create_from_url($url, array $params = [])
    {
        if (!isset($params['site_id'])) {
            throw new Exception('site_id is required in params');
        }

        $timeout = $params['timeout'] ?? 30;

        try {
            // Download file using Laravel HTTP client
            $response = \Illuminate\Support\Facades\Http::timeout($timeout)->get($url);

            if (!$response->successful()) {
                throw new Exception("Failed to download file from URL: HTTP {$response->status()}");
            }

            $content = $response->body();

            // Determine filename from URL or params
            $filename = $params['filename'] ?? basename(parse_url($url, PHP_URL_PATH));
            if (empty($filename) || $filename === '/') {
                $filename = 'downloaded_file_' . uniqid();
            }

            // Use create_from_string to handle the rest
            return static::create_from_string($content, $filename, $params);
        } catch (Exception $e) {
            throw new Exception('Error downloading file from URL: ' . $e->getMessage());
        }
    }

    /**
     * Resolve the PIPELINE mime for an attachment - the mime the processing registries (viewer,
     * PDF-rendition/convertible, text-extractor, thumbnail renderer) and the coarse file_type_id
     * bucket match against. This is distinct from the stored/served mime_type (the raw byte sniff,
     * a serve-time security boundary that is never rewritten here).
     *
     * Policy ("trust the more reliable signal per family"):
     *   1. Document extension wins UNCONDITIONALLY. If $extension (lowercased) is a key in
     *      config('rsx.files.document_mime_by_extension'), return the mapped canonical mime -
     *      the extension is a deliberate authored claim and libmagic's heuristic OOXML sniff is
     *      per-file flaky (a valid .docx can sniff as application/zip).
     *   2. Image sniff wins. Otherwise, if the sniff is an image/* type, return it - image magic
     *      bytes are unambiguous, and this corrects a misnamed extension (webp saved as .png).
     *   3. Everything else keeps the sniff (generic types fall through to Icon_Viewer, as today).
     *
     * Pure (args in, string out) so the extraction path - which has no model instance - can reuse
     * it, and so it is trivially unit-testable.
     *
     * @param string|null $sniffed_mime The libmagic byte-sniff result.
     * @param string|null $extension    The attachment's file extension (case-insensitive).
     * @return string The pipeline mime (empty string only when both inputs are empty/unknown).
     */
    public static function resolve_pipeline_mime(?string $sniffed_mime, ?string $extension): string
    {
        // 1) Document extension wins unconditionally.
        $ext = strtolower(trim((string) $extension));
        if ($ext !== '') {
            $map = config('rsx.files.document_mime_by_extension', []);
            if (isset($map[$ext])) {
                return $map[$ext];
            }
        }

        $sniffed = (string) $sniffed_mime;

        // 2) Image sniff wins (unambiguous magic bytes; corrects a misnamed extension).
        if (str_starts_with($sniffed, 'image/')) {
            return $sniffed;
        }

        // 3) Everything else keeps the sniff.
        return $sniffed;
    }

    /**
     * The pipeline mime for THIS attachment: resolve_pipeline_mime() over its stored sniffed
     * mime_type and file_extension. Use this - never the raw mime_type column - for viewer /
     * convertible / extractor / thumbnail routing and the coarse file_type_id bucket.
     *
     * @return string
     */
    public function pipeline_mime(): string
    {
        // A degraded (unparseable-image) attachment routes as a generic download: no image
        // viewer, no Imagick thumbnail retry, no image bucket. The raw served mime_type column
        // is untouched - only this PIPELINE mime is generalized.
        if ($this->preview_unavailable) {
            return 'application/octet-stream';
        }

        return static::resolve_pipeline_mime($this->mime_type, $this->file_extension);
    }

    /**
     * Determine file type from mime type
     *
     * This performs initial categorization based on MIME type.
     * The process_file() method will refine this for animated images.
     *
     * Callers pass the PIPELINE mime (resolve_pipeline_mime()), not the raw sniff, so a
     * zip-sniffed OOXML document buckets as DOCUMENT rather than ARCHIVE.
     *
     * @param string $mime_type
     * @return int
     */
    public static function determine_file_type($mime_type)
    {
        // Images (including SVG, WebP, PSD)
        if (strpos($mime_type, 'image/') === 0) {
            // Initially categorize as static image
            // process_file() will upgrade to animated_image if needed
            return 1; // image
        }

        // Videos (including WebM)
        if (strpos($mime_type, 'video/') === 0) {
            return 3; // video
        }

        // Archives
        if (in_array($mime_type, [
            'application/zip',
            'application/x-zip-compressed',
            'application/x-rar',
            'application/x-rar-compressed',
            'application/vnd.rar',
            'application/x-tar',
            'application/x-7z-compressed',
            'application/gzip',
            'application/x-bzip2',
        ])) {
            return 4; // archive
        }

        // Text files
        if (strpos($mime_type, 'text/') === 0) {
            return 5; // text
        }

        // Documents (PDF, Office files)
        if (in_array($mime_type, [
            'application/pdf',
            'application/msword',
            'application/vnd.ms-word',
            'application/vnd.ms-excel',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.oasis.opendocument.presentation',
            'application/rtf',
        ]) || strpos($mime_type, 'application/vnd.openxmlformats-officedocument') === 0) {
            return 6; // document
        }

        return 7; // other
    }

    /**
     * Process file to extract metadata using ImageMagick
     *
     * Extracts:
     * - Dimensions (width, height) for images and videos
     * - Duration for videos and animated images
     * - Animation detection and frame count
     *
     * Updates the attachment record with extracted metadata.
     * Automatically upgrades file_type_id from IMAGE to ANIMATED_IMAGE when animation is detected.
     *
     * Note: PDF processing is skipped because PDFs are documents, not images.
     * For PDF metadata (page count, dimensions), use a PDF-specific library.
     *
     * @return void
     * @throws Exception if ImageMagick is not available or processing fails
     */
    public function process_file()
    {
        // Get the physical file path (materializing external bytes on demand).
        $file_path = $this->resolve_storage()->get_full_path();

        if (!file_exists($file_path)) {
            throw new Exception("File does not exist: {$file_path}");
        }

        // Skip processing for documents (including PDFs)
        // PDFs would require PDF-specific processing
        if ($this->is_document()) {
            return;
        }

        // Check if ImageMagick extension is available
        if (!extension_loaded('imagick')) {
            throw new Exception('ImageMagick PHP extension is not installed');
        }

        try {
            // Process images and videos
            if ($this->is_image() || $this->is_video()) {
                $this->__process_image_or_video($file_path);
            }

            // Save changes if any metadata was extracted
            if ($this->isDirty()) {
                $this->save();
            }
        } catch (\Throwable $e) {
            // ImageMagick could not PARSE the bytes (content-parse failure). Some Imagick failures
            // surface as non-ImagickException Throwables, so catch broadly - the MISSING-binary
            // fatal is BEFORE the try, so it still fatals loud and never lands here.
            // Log for debugging purposes.
            error_log("ImageMagick processing failed for {$file_path}: " . $e->getMessage());

            if (config('rsx.attachments.reject_unparseable_images', false)) {
                // Strict mode: reject the upload outright. The endpoint catches this, returns a
                // 4xx, and cleans up the already-persisted row (see the exception's docblock -
                // create_from_upload() is NOT transactional).
                throw new Unparseable_Upload_Exception(
                    "Uploaded image could not be parsed: {$this->file_name}"
                );
            }

            // Default: accept + degrade to a generic, non-previewable file. Dimensions are already
            // null; flag it and demote its type so the viewer/thumbnail/text pipelines treat it as
            // a plain download (pipeline_mime() also returns application/octet-stream once flagged).
            $this->preview_unavailable = true;
            $this->file_type_id = self::FILE_TYPE_OTHER;
            $this->save();
        }
    }

    /**
     * Process image or video file to extract dimensions and animation data
     *
     * @param string $file_path
     * @return void
     * @throws ImagickException
     */
    private function __process_image_or_video($file_path)
    {
        $imagick = new Imagick($file_path);

        // Get dimensions from first frame
        $this->width = $imagick->getImageWidth();
        $this->height = $imagick->getImageHeight();

        // Get format for special handling
        $format = strtolower($imagick->getImageFormat());

        // Count frames to detect animation
        $frame_count = $imagick->getNumberImages();

        // Formats that should NOT be treated as animated even with multiple frames
        // PSD, TIFF, ICO, and other multi-layer formats have frames but aren't animations
        $non_animated_formats = ['psd', 'tiff', 'tif', 'ico', 'xcf'];

        if ($frame_count > 1 && !in_array($format, $non_animated_formats)) {
            // This is an animated image (GIF, WebP, APNG, etc.)
            $this->is_animated = true;
            $this->frame_count = $frame_count;

            // Calculate duration for animated images
            // Sum delays across all frames
            $total_delay = 0;
            $imagick->setFirstIterator();
            do {
                // Get delay in 1/100th of a second (centiseconds)
                $delay = $imagick->getImageDelay();
                $total_delay += $delay;
            } while ($imagick->nextImage());

            // Convert centiseconds to seconds (rounded)
            $this->duration = (int) round($total_delay / 100);

            // Upgrade file type from IMAGE to ANIMATED_IMAGE
            if ($this->file_type_id == self::FILE_TYPE_IMAGE) {
                $this->file_type_id = self::FILE_TYPE_ANIMATED_IMAGE;
            }
        } else {
            // Static image (or multi-layer format like PSD)
            $this->is_animated = false;
            $this->frame_count = in_array($format, $non_animated_formats) ? null : 1;
            $this->duration = null;
        }

        // For videos, try to get duration from metadata
        if ($this->file_type_id == self::FILE_TYPE_VIDEO) {
            // ImageMagick doesn't reliably extract video duration
            // For now, leave duration null for videos
            // Future enhancement: use ffmpeg/ffprobe for accurate video metadata
            $this->is_animated = false; // Videos are not "animated images"
            $this->frame_count = null;
        }

        $imagick->clear();
        $imagick->destroy();
    }

    /**
     * Scope to get files by category
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $category
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInCategory($query, $category)
    {
        return $query->where('fileable_category', $category);
    }

    /**
     * Scope to get files by type meta
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type_meta
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByTypeMeta($query, $type_meta)
    {
        return $query->where('fileable_type_meta', $type_meta);
    }

    /**
     * Get decoded fileable_meta JSON
     *
     * @return array|null
     */
    public function get_meta()
    {
        if (empty($this->fileable_meta)) {
            return null;
        }

        return json_decode($this->fileable_meta, true);
    }

    /**
     * Set fileable_meta from array
     *
     * @param array $meta
     * @return void
     */
    public function set_meta(array $meta)
    {
        $this->fileable_meta = json_encode($meta);
    }

    /**
     * Scope to get files for a specific model
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type
     * @param int $id
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForModel($query, $type, $id)
    {
        return $query->where('fileable_type', $type)
                    ->where('fileable_id', $id);
    }

    /**
     * The client IP to stamp on a newly created attachment, or null when there is no request
     * context (CLI, tasks, imports, migrations). ONE source for every create_* factory - the
     * ingest paths must not diverge on what "who uploaded this" means.
     *
     * Audit metadata only. Nothing in the framework reads it back for authorization.
     *
     * @return string|null
     */
    protected static function __creation_ip_address(): ?string
    {
        return \App\RSpade\Core\Session\Session::get_client_ip();
    }

    /**
     * Check whether this attachment may be CLAIMED - i.e. attached to a record - right now.
     *
     * Two conditions, both structural:
     * 1. It is not already assigned to a polymorphic parent (SINGLE CLAIM: an attachment is
     *    claimed once; a claimed key can never be re-pointed at another record).
     * 2. Its site_id matches the current site (tenant isolation).
     *
     * What defends the claim in practice is the KEY: 64 characters of random hex, handed to
     * exactly one uploader, unenumerable. Combined with the single-claim rule and the claim
     * window (an unattached upload is swept after
     * config('rsx.attachments.unattached_claim_window_hours')), a key is only useful to the
     * party that received it, and only briefly.
     *
     * This is deliberately NOT a per-user policy. There is no "did the same person upload
     * this" test any more - the previous session_id comparison read the STAFF session facade
     * unconditionally (so a portal upload matched only by accident) and made claimability a
     * property of a browser session rather than of the attachment. WHO may upload and WHO may
     * claim are application decisions, declared through the file.upload.authorize gate and the
     * app's own endpoint authorization; created_by_ip_address is audit metadata, never a guard.
     *
     * @return bool True if this attachment may be claimed
     */
    public function can_user_assign_this_file(): bool
    {
        // Must not already be assigned to something
        if ($this->fileable_type || $this->fileable_id) {
            return false;
        }

        // Get current site ID (even if null for non-multi-tenant setups).
        //
        // Realm-aware: /_upload stamps site_id from Portal_Session on a portal request, so
        // the claim check has to ask the same facade or a portal upload can never be claimed
        // by the portal endpoint that asked for it. See
        // docs.dev/audits/portal_realm_session_audit_2026_08_09.md.
        $current_site_id = \App\RSpade\Core\Portal\Rsx_Portal::is_portal_request()
            ? \App\RSpade\Core\Portal\Portal_Session::get_site_id()
            : \App\RSpade\Core\Session\Session::get_site_id();

        // Site IDs must match (both null is a match)
        if ($this->site_id !== $current_site_id) {
            return false;
        }

        return true;
    }

    /**
     * Attach this file to a model (replaces any existing attachment in category)
     *
     * Use this for single-file attachments like profile photos, company logos, etc.
     * Any existing attachment with the same category will be detached (not deleted).
     *
     * @param \App\RSpade\Core\Database\Models\Rsx_Model_Abstract $model The model to attach to
     * @param string $category Category identifier (e.g., 'profile_photo', 'logo')
     * @return void
     * @throws \App\RSpade\Core\Debug\Rsx_Caller_Exception If not authorized or already attached
     */
    public function attach_to($model, string $category): void
    {
        // Validate attachment is claimable. The claim guard applies only to user-uploaded
        // attachments; external (handler-backed) attachments are created programmatically by
        // trusted app code (create_external) and are attached by that same code.
        if ($this->handler_class === null && !$this->can_user_assign_this_file()) {
            throw new \App\RSpade\Core\Debug\Rsx_Caller_Exception('Not authorized to assign this attachment');
        }

        // Replace any existing attachment with this category
        // Use simple class name (type_ref system stores simple names, not FQCNs)
        $class_name = class_basename($model);
        static::where('fileable_type', $class_name)
            ->where('fileable_id', $model->id)
            ->where('fileable_category', $category)
            ->update([
                'fileable_type' => null,
                'fileable_id' => null,
                'fileable_category' => null,
            ]);

        // Assign this attachment
        $this->fileable_type = $class_name;
        $this->fileable_id = $model->id;
        $this->fileable_category = $category;
        $this->save();
    }

    /**
     * Add this file to a model (does not replace existing attachments in category)
     *
     * Use this for multiple-file attachments like project documents, photo galleries, etc.
     * This attachment will be added alongside any existing attachments in the same category.
     *
     * @param \App\RSpade\Core\Database\Models\Rsx_Model_Abstract $model The model to attach to
     * @param string $category Category identifier (e.g., 'documents', 'photos')
     * @return void
     * @throws \App\RSpade\Core\Debug\Rsx_Caller_Exception If not authorized or already attached
     */
    public function add_to($model, string $category): void
    {
        // Validate attachment is claimable. The claim guard applies only to user-uploaded
        // attachments; external (handler-backed) attachments are created programmatically by
        // trusted app code (create_external) and are attached by that same code.
        if ($this->handler_class === null && !$this->can_user_assign_this_file()) {
            throw new \App\RSpade\Core\Debug\Rsx_Caller_Exception('Not authorized to assign this attachment');
        }

        // Assign this attachment (without replacing others)
        // Use simple class name (type_ref system stores simple names, not FQCNs)
        $this->fileable_type = class_basename($model);
        $this->fileable_id = $model->id;
        $this->fileable_category = $category;
        $this->save();
    }

    /**
     * Detach this file from its current assignment
     *
     * Clears the polymorphic relationship fields without deleting the attachment.
     * The attachment becomes unassigned and available for reassignment.
     *
     * @return void
     */
    public function detach(): void
    {
        $this->fileable_type = null;
        $this->fileable_id = null;
        $this->fileable_category = null;
        $this->save();
    }

    // -------------------------------------------------------------------------
    // Retention / disposal lifecycle (see File_Disposal_Service, rsx:man file_disposal)
    // -------------------------------------------------------------------------

    /**
     * Enumerate attachments currently in the RETENTION WINDOW (soft-deleted but not yet
     * destroyed) - the recovery surface an app builds a "Recently Deleted" view on.
     * Tenant-scoped (this model is site-scoped). Optionally narrowed to a fileable owner
     * and/or a category. Permanently-destroyed rows are always excluded.
     *
     * With BOTH arguments omitted this is "every retained attachment on the install", so
     * it returns an Rsx_Result_Set - foreach it, count() it - rather than materializing a
     * set whose size is the whole recycle bin.
     *
     * @param object|null $fileable Optional owner model to scope to (fileable_type/id)
     * @param string|null $category Optional fileable_category filter
     * @return \App\RSpade\Core\Database\Rsx_Result_Set
     */
    public static function get_deleted_files($fileable = null, $category = null)
    {
        $query = static::withTrashed()
            ->whereNotNull('deleted_at')
            ->whereNull('destroyed_at');

        if ($fileable !== null) {
            $query->where('fileable_type', class_basename($fileable))
                ->where('fileable_id', $fileable->id);
        }
        if ($category !== null) {
            $query->where('fileable_category', $category);
        }

        // orderByDesc is dropped by iteration (Rsx_Result_Set walks by primary key);
        // sort in the view if presentation order matters.
        return $query->result_set();
    }

    /**
     * Restore a soft-deleted attachment out of the retention window (clears deleted_at).
     * THROWS if the attachment has already been permanently DESTROYED (its blob claim was
     * released and the bytes may be gone) or if its backing bytes are no longer resolvable.
     *
     * @return void
     */
    public function undelete(): void
    {
        if ($this->destroyed_at !== null) {
            throw new Exception('Cannot undelete attachment ' . $this->id . ': it has been permanently destroyed.');
        }
        if (!$this->has_blob() && !$this->has_handler()) {
            throw new Exception('Cannot undelete attachment ' . $this->id . ': its backing bytes are gone.');
        }

        $this->restore(); // SoftDeletes: clears deleted_at
    }

    /**
     * Permanently destroy this attachment NOW, bypassing BOTH the retention window and the
     * file.attachment.destroy.hold gate - for GDPR-style erasure and internal rollback of a
     * rejected upload. Stamps deleted_at + destroyed_at (the row PERSISTS as an audit
     * tombstone) and immediately releases the backing blob if no live-or-retained attachment
     * still pins it.
     *
     * THE GATE IS BYPASSED; THE ANNOUNCEMENT IS NOT. Skipping
     * file.attachment.destroy.hold is the entire point of "force" - a hold must never veto
     * a forced erasure. But file.attachment.destroyed is not a permission, it is a
     * statement of fact ("this attachment is now permanently gone"), and that fact is
     * equally true down both paths. An app tombstone keyed on the hook (a Recently Deleted
     * view, a documents.destroyed_at stamp) would otherwise go on listing a file as
     * restorable whose bytes are already released.
     *
     * Same gate -> action -> stamp -> save ORDERING as
     * File_Disposal_Service::__destroy_attachment(), so a listener observes identical state
     * whichever path destroyed the attachment.
     *
     * LISTENER FAILURE POLICY DIFFERS FROM THE SCHEDULED PATH, DELIBERATELY. There, a
     * throwing listener aborts that attachment's destruction and it retries next run. A
     * forced destroy has no next run, and its most important caller is
     * create_from_upload()'s rejected-upload rollback - which must not be derailed by an
     * app listener. So here: catch, log, and proceed with the destruction.
     *
     * @return void
     */
    public function force_destroy(): void
    {
        $storage_id = $this->file_storage_id;

        try {
            Rsx::trigger_action('file.attachment.destroyed', $this);
        } catch (Throwable $e) {
            Log::error('file.attachment.destroyed listener threw during force_destroy of attachment '
                . $this->id . '; destroying anyway: ' . $e->getMessage());
        }

        $now = Rsx_Time::now_iso();
        if ($this->deleted_at === null) {
            $this->deleted_at = $now;
        }
        $this->destroyed_at = $now;
        $this->save();

        if ($storage_id) {
            File_Disposal_Service::release_blob_if_orphaned((int) $storage_id);
        }
    }
}