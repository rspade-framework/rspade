<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Search\Search_Index_Model;
use App\RSpade\Core\Task\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * rsx:search:reindex - re-queue document text extraction, or report the index state.
 *
 * Re-queuing is uniform: resolve a set of _file_storage ids, set their is_indexed flag back to
 * 0 (chunked), then kick Search_Index_Service::index_pending. The cron/kick then re-extracts
 * them one at a time. Exactly ONE selector must be given (fail loud otherwise).
 *
 * Selectors:
 *   --all               re-queue every blob
 *   --failed            re-queue blobs whose last extraction FAILED
 *   --below-version=N   re-queue blobs indexed by an extractor generation older than N
 *   --storage=ID        re-queue one blob by _file_storage id
 *   --attachment=ID     re-queue the blob backing one attachment (materializes external bytes)
 *   --status            report counts only (no re-queue)
 */
class Search_Reindex_Command extends Command
{
    protected $signature = 'rsx:search:reindex
                            {--all : Re-queue every blob}
                            {--failed : Re-queue blobs whose last extraction FAILED}
                            {--below-version= : Re-queue blobs indexed below extractor version N}
                            {--storage= : Re-queue one blob by _file_storage id}
                            {--attachment= : Re-queue the blob backing one attachment id (materializes)}
                            {--status : Report index counts only (no re-queue)}';

    protected $description = 'Re-queue document text extraction, or report index state (--status)';

    /** Blob ids per re-queue statement - the ceiling on resident ids however large the match. */
    private const REQUEUE_CHUNK_SIZE = 1000;

    public function handle(): int
    {
        // Exactly one selector.
        $selectors = [
            '--all' => (bool) $this->option('all'),
            '--failed' => (bool) $this->option('failed'),
            '--below-version' => $this->option('below-version') !== null,
            '--storage' => $this->option('storage') !== null,
            '--attachment' => $this->option('attachment') !== null,
            '--status' => (bool) $this->option('status'),
        ];

        $chosen = array_keys(array_filter($selectors));

        if (count($chosen) !== 1) {
            $this->error('[ERROR] Exactly one selector is required: --all | --failed | --below-version=N | --storage=ID | --attachment=ID | --status');
            if (count($chosen) > 1) {
                $this->line('  Given: ' . implode(', ', $chosen));
            }
            return 1;
        }

        $selector = $chosen[0];

        if ($selector === '--status') {
            $this->__print_status();
            return 0;
        }

        $storage_ids = $this->__resolve_storage_ids($selector);
        if ($storage_ids === null) {
            return 1;
        }

        $queued = $this->__requeue($storage_ids, $selector === '--all');

        if ($queued === 0) {
            $this->line('[OK] No blobs matched - nothing re-queued.');
            return 0;
        }

        Task::dispatch('Search_Index_Service', 'index_pending');

        $this->line("[OK] Re-queued {$queued} blob(s) for extraction; extraction task dispatched.");
        return 0;
    }

    /**
     * Resolve the selector to the target _file_storage ids. Returns null on a resolution error
     * (already reported). For --all, returns an empty array with the caller handling it as
     * "every row" (see __requeue).
     *
     * The set-valued selectors return a SUB-QUERY, not a list: these can match every indexed
     * blob on the install, and plucking six figures of ids into PHP to hand straight back to
     * MySQL as an IN list is how a maintenance command becomes a memory fatal.
     *
     * @param string $selector
     * @return array<int>|\Illuminate\Database\Eloquent\Builder|null
     */
    private function __resolve_storage_ids(string $selector)
    {
        if ($selector === '--all') {
            // Sentinel: __requeue($ids, true) updates every row without an id list.
            return [];
        }

        if ($selector === '--failed') {
            return Search_Index_Model::where('indexable_type', 'File_Storage_Model')
                ->where('status_id', Search_Index_Model::STATUS_FAILED)
                ->select('indexable_id');
        }

        if ($selector === '--below-version') {
            $version = (int) $this->option('below-version');
            return Search_Index_Model::where('indexable_type', 'File_Storage_Model')
                ->where('extractor_version', '<', $version)
                ->select('indexable_id');
        }

        if ($selector === '--storage') {
            $id = (int) $this->option('storage');
            $storage = File_Storage_Model::find($id);
            if (!$storage) {
                $this->error("[ERROR] No _file_storage row with id {$id}");
                return null;
            }
            return [$storage->id];
        }

        // --attachment: materialize the backing blob (external bytes become resident) then
        // re-queue it.
        $attachment_id = (int) $this->option('attachment');
        $attachment = File_Attachment_Model::find($attachment_id);
        if (!$attachment) {
            $this->error("[ERROR] No file attachment with id {$attachment_id}");
            return null;
        }

        $storage = $attachment->resolve_storage();
        return [$storage->id];
    }

    /**
     * Set is_indexed=0 on the target blobs (chunked). Returns the count queued.
     *
     * A sub-query target is walked by keyset, so no matter how many blobs match, at most
     * CHUNK ids are ever resident. Keying on id (which the update does not touch) keeps the
     * walk terminating regardless of what the update changes.
     *
     * Deliberately plucks bare ids rather than using result_set(): the work here is a
     * set-based UPDATE, and hydrating a full model per blob just to flip one column would
     * be strictly slower for no benefit.
     *
     * @param array<int>|\Illuminate\Database\Eloquent\Builder $storage_ids
     * @param bool $all
     * @return int
     */
    private function __requeue($storage_ids, bool $all): int
    {
        if ($all) {
            return File_Storage_Model::query()->update(['is_indexed' => 0]);
        }

        if (!is_array($storage_ids)) {
            $queued = 0;
            $last_id = 0;

            while (true) {
                $ids = File_Storage_Model::whereIn('id', $storage_ids)
                    ->where('id', '>', $last_id)
                    ->orderBy('id')
                    ->limit(self::REQUEUE_CHUNK_SIZE)
                    ->pluck('id')
                    ->all();

                if (empty($ids)) {
                    break;
                }

                $last_id = (int) end($ids);
                $queued += File_Storage_Model::whereIn('id', $ids)->update(['is_indexed' => 0]);
            }

            return $queued;
        }

        $storage_ids = array_values(array_unique(array_map('intval', $storage_ids)));
        if (empty($storage_ids)) {
            return 0;
        }

        $queued = 0;
        foreach (array_chunk($storage_ids, self::REQUEUE_CHUNK_SIZE) as $chunk) {
            $queued += File_Storage_Model::whereIn('id', $chunk)->update(['is_indexed' => 0]);
        }

        return $queued;
    }

    /**
     * Print the index-state counts table.
     */
    private function __print_status(): void
    {
        $total_blobs = File_Storage_Model::count();
        $queued = File_Storage_Model::where('is_indexed', 0)->count();
        $indexed = $total_blobs - $queued;

        $this->line('Document extraction index status');
        $this->line('  extraction enabled : ' . (config('rsx.search.enabled', true) ? 'yes' : 'no'));
        $this->line('  current version    : ' . (int) config('rsx.search.extractor_version', 1));
        $this->newLine();

        $this->table(['Metric', 'Count'], [
            ['Total blobs (_file_storage)', $total_blobs],
            ['Queued (is_indexed=0)', $queued],
            ['Indexed (is_indexed=1)', $indexed],
        ]);

        // By status.
        $status_rows = [];
        foreach (Search_Index_Model::status_id__enum() as $id => $meta) {
            $count = Search_Index_Model::where('indexable_type', 'File_Storage_Model')
                ->where('status_id', $id)
                ->count();
            $status_rows[] = [$meta['label'], $count];
        }
        $this->newLine();
        $this->line('By extraction status:');
        $this->table(['Status', 'Count'], $status_rows);

        // Version histogram.
        $version_counts = Search_Index_Model::where('indexable_type', 'File_Storage_Model')
            ->select('extractor_version', DB::raw('COUNT(*) as c'))
            ->groupBy('extractor_version')
            ->orderBy('extractor_version')
            ->get();

        $version_rows = [];
        foreach ($version_counts as $row) {
            $version_rows[] = ['v' . $row->extractor_version, $row->c];
        }
        if (empty($version_rows)) {
            $version_rows[] = ['(no index rows)', 0];
        }
        $this->newLine();
        $this->line('Extractor version histogram:');
        $this->table(['Version', 'Count'], $version_rows);
    }
}
