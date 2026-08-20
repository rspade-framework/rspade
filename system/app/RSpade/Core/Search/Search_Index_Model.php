<?php

namespace App\RSpade\Core\Search;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Search_Index_Model - the extracted-text store behind document full-text search.
 *
 * One row per DEDUPLICATED file blob: indexable_type is always 'File_Storage_Model'
 * and indexable_id is the blob id (the polymorphic columns are retained for shape, but
 * the pipeline keys exclusively on the storage blob). Because the key is the dedup
 * blob, identical bytes are extracted exactly once no matter how many attachments
 * reference them, and the row carries NO site_id - the deduplicated blob is cross-site,
 * so site scoping happens at the attachment join (File_Attachment_Model::search_text).
 *
 * Rows are written by Search_Index_Service after an extraction attempt, so status_id is
 * always terminal (EXTRACTED / FAILED / UNSUPPORTED) - there is no stored PENDING; the
 * absence of a row (with _file_storage.is_indexed = 0) IS the pending queue.
 *
 * content holds the extracted text under a FULLTEXT index; search() runs MATCH...AGAINST
 * over it. See rsx:man document_search.
 */


/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _search_indexes
 *
 * @property int $id
 * @property int $indexable_type
 * @property int $indexable_id
 * @property int $status_id
 * @property string $content
 * @property array $metadata
 * @property string $indexed_at
 * @property string $extraction_method
 * @property string $error
 * @property int $extractor_version
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @property-read string $status_id__label
 * @property-read string $status_id__constant
 *
 * @method static array status_id__enum() Get all enum definitions with full metadata
 * @method static array status_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array status_id__enum_labels() Get simple id => label map
 * @method static array status_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
class Search_Index_Model extends Rsx_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const STATUS_EXTRACTED = 1;
    const STATUS_FAILED = 2;
    const STATUS_UNSUPPORTED = 3;

    /**
     * Infrastructure model - never a realtime EMITTER kick source. Extraction rows churn from
     * the background cron and must not trigger the emitter recompute pipeline. (This model is
     * not $realtime; the flag just documents/enforces that a write here never wakes emitters.)
     *
     * @var bool
     */
    public static $realtime_silent = true;

    /**
     * Enum field definitions.
     *
     * status_id is the extraction lifecycle. There is NO stored PENDING state - the absence of
     * a row (with _file_storage.is_indexed=0) IS the queue. A row is written only after an
     * extraction attempt, so status_id is always one of these three.
     *
     * @var array
     */
    public static $enums = [
        'status_id' => [
            1 => ['constant' => 'STATUS_EXTRACTED', 'label' => 'Extracted', 'badge' => 'bg-success', 'order' => 1],
            2 => ['constant' => 'STATUS_FAILED', 'label' => 'Failed', 'badge' => 'bg-danger', 'order' => 2],
            3 => ['constant' => 'STATUS_UNSUPPORTED', 'label' => 'Unsupported', 'badge' => 'bg-secondary', 'order' => 3],
        ],
    ];
    public static $rel = [];

    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per indexed blob, and the content column is large.
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
    protected $table = '_search_indexes';

    /**
     * Polymorphic type reference columns
     *
     * indexable_type stores integer ID in database but exposes class name string
     * @var array
     */
    protected static $type_ref_columns = ['indexable_type'];

    /**
     * Get decoded metadata JSON
     *
     * @return array|null
     */
    public function get_metadata()
    {
        if (empty($this->metadata)) {
            return null;
        }

        return json_decode($this->metadata, true);
    }

    /**
     * Set metadata from array
     *
     * @param array $metadata
     * @return void
     */
    public function set_metadata(array $metadata)
    {
        $this->metadata = json_encode($metadata);
    }

    /**
     * Full-text search across all indexed content
     *
     * Uses MySQL MATCH...AGAINST for full-text search
     *
     * @param string $query Search query
     * @param string $mode 'BOOLEAN' or 'NATURAL LANGUAGE'
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function search($query, $mode = 'BOOLEAN')
    {
        $mode_string = $mode === 'BOOLEAN' ? 'IN BOOLEAN MODE' : 'IN NATURAL LANGUAGE MODE';

        return static::whereRaw("MATCH(content) AGAINST(? {$mode_string})", [$query]);
    }

    /**
     * Scope to get indexes for a specific model
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type Model class name
     * @param int $id Model ID
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForModel($query, $type, $id)
    {
        return $query->where('indexable_type', $type)
                    ->where('indexable_id', $id);
    }

    /**
     * Find or create search index for a model
     *
     * @param string $indexable_type
     * @param int $indexable_id
     * @return static
     */
    public static function find_or_create_for_model($indexable_type, $indexable_id)
    {
        $index = static::forModel($indexable_type, $indexable_id)->first();

        if (!$index) {
            $index = new static();
            $index->indexable_type = $indexable_type;
            $index->indexable_id = $indexable_id;
        }

        return $index;
    }
}