<?php

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Database\Models\Rsx_System_Model_Abstract;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Zip_Download_Request_Model - one row per minted multi-file ZIP download (_zip_download_requests).
 *
 * Reworks the multi-file ZIP download into a database-backed, server-authorized flow.
 * Server-side app code records the file set with create_request(), receives an opaque
 * download_key, and hands the browser a plain GET URL (get_download_url()); the browser
 * simply navigates to it. The stored file set is the SAME entry format the old POST
 * accepted: a JSON array of {key, name?}.
 *
 * create_request() validates the STRUCTURE of the file set fail-loud, but does NOT
 * authorize the files: authorization happens per-file at download time against the
 * downloading session (the exact dual-gate cascade GET /_download/:key uses), so a
 * minted key never confers access the caller lacked.
 *
 * Rows are NOT consumed on use (a browser may retry a download); they die by expiry
 * (config rsx.attachments.zip_request_retention_hours) - enforced at serve time by
 * is_expired() AND pruned by Zip_Download_Cleanup_Service.
 *
 * Infrastructure table: a download request is transient plumbing, never user-facing data
 * anyone subscribes to, so realtime emission is suppressed.
 *
 * @property int $id
 * @property string $download_key
 * @property string $files
 * @property string|null $zip_name
 * @property string $created_at
 * @property string $updated_at
 *
 * @mixin \Eloquent
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _zip_download_requests
 *
 * @property int $id
 * @property string $download_key
 * @property string $files
 * @property string $zip_name
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
class Zip_Download_Request_Model extends Rsx_System_Model_Abstract
{
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per multi-file download a user requests.
     *
     * Consumed by the DB-UNBOUNDED-01 code-quality rule, which flags a bare ->get() /
     * ->pluck() on this model in framework code and points at ->result_set(). It is a
     * DECLARATION, not a runtime gate - a small, well-narrowed query here is still fine.
     * See: the Do The Whole Job section of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;

    protected $table = '_zip_download_requests';

    public static $enums = [];

    /**
     * Transient download plumbing - never kicks the realtime emitter engine.
     * @var bool
     */
    public static $realtime_silent = true;

    /**
     * Record a multi-file ZIP download request and return its persisted row.
     *
     * The file set is the same shape the endpoint has always accepted: an array of
     * {key, name?} entries. STRUCTURE is validated fail-loud here (this is server-side app
     * code, not user input) - a malformed set is a programming error, not a denial. This
     * does NOT authorize the files: authorization runs per-file at download time.
     *
     * @param array       $files    Non-empty array of ['key' => string, 'name'? => string].
     * @param string|null $zip_name Optional download filename (sanitized at serve time).
     * @return self
     */
    public static function create_request(array $files, ?string $zip_name = null): self
    {
        if (count($files) === 0) {
            throw new \RuntimeException('Zip_Download_Request_Model::create_request() requires at least one file entry');
        }

        foreach ($files as $index => $entry) {
            if (!is_array($entry)) {
                throw new \RuntimeException("Zip_Download_Request_Model::create_request() entry {$index} is not an array");
            }
            if (!isset($entry['key']) || !is_string($entry['key'])) {
                throw new \RuntimeException("Zip_Download_Request_Model::create_request() entry {$index} is missing a string 'key'");
            }
            if (isset($entry['name']) && !is_string($entry['name'])) {
                throw new \RuntimeException("Zip_Download_Request_Model::create_request() entry {$index} 'name' must be a string when present");
            }
            foreach (array_keys($entry) as $entry_key) {
                if ($entry_key !== 'key' && $entry_key !== 'name') {
                    throw new \RuntimeException("Zip_Download_Request_Model::create_request() entry {$index} has an unexpected key '{$entry_key}' (only key/name allowed)");
                }
            }
        }

        $request = new self();
        $request->download_key = random_hash(32);
        $request->files = json_encode($files);
        $request->zip_name = $zip_name;
        $request->save();

        return $request;
    }

    /**
     * Look up a request by its opaque download key.
     *
     * @param string $key
     * @return self|null
     */
    public static function find_by_download_key(string $key): ?self
    {
        return static::where('download_key', $key)->first();
    }

    /**
     * The download URL a browser navigates to. Type-safe route generation (never a
     * hardcoded path).
     *
     * @return string
     */
    public function get_download_url(): string
    {
        return Rsx::Route('File_Attachment_Controller::download_multiple_zip', ['key' => $this->download_key]);
    }

    /**
     * True when this request is older than the configured validity window and must no
     * longer serve. Model datetime attributes are already ISO strings (never Carbon), so
     * age is read directly with the string-based time class.
     *
     * @return bool
     */
    public function is_expired(): bool
    {
        $retention_hours = (int) config('rsx.attachments.zip_request_retention_hours', 24);

        return Rsx_Time::seconds_since($this->created_at) >= $retention_hours * 3600;
    }

    /**
     * The decoded file-set entries ({key, name?} array).
     *
     * @return array
     */
    public function get_files(): array
    {
        return json_decode($this->files, true);
    }
}
