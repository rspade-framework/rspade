<?php

namespace App\RSpade\Commands\Thumbnails;

use Illuminate\Console\Command;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Attachment_Controller;

class Thumbnails_Generate_Command extends Command
{
    /** Attachments held in memory at once while walking the set. */
    private const BATCH_SIZE = 500;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:thumbnails:generate
        {--preset= : Generate specific preset for all attachments (e.g., --preset=profile)}
        {--key= : Generate thumbnails for specific attachment key}
        {--all : Generate all presets for all attachments (default if no options specified)}
        {--include-external : Also materialize + render external (handler-backed) attachments. By default external attachments are SKIPPED to avoid mass-materializing remote bytes during bulk cache warming.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pre-generate preset thumbnails for attachments (useful for cache warming after deployment)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $preset_filter = $this->option('preset');
        $key_filter = $this->option('key');
        $generate_all = $this->option('all') || (!$preset_filter && !$key_filter);

        // Get presets to generate
        $presets = config('rsx.thumbnails.presets', []);
        if (empty($presets)) {
            $this->error('No thumbnail presets defined in config');
            return 1;
        }

        $presets_to_generate = $presets;
        if ($preset_filter) {
            if (!isset($presets[$preset_filter])) {
                $this->error("Preset '{$preset_filter}' not defined in config");
                return 1;
            }
            $presets_to_generate = [$preset_filter => $presets[$preset_filter]];
        }

        // Attachments to process. Unfiltered this is every attachment on the install, and
        // the work per row (decode, resize, write) means the set is never small enough to
        // hold whole - so it is walked a keyset page at a time.
        $query = File_Attachment_Model::query();
        if ($key_filter) {
            $query->where('key', $key_filter);
        }

        $attachments = $query->result_set(self::BATCH_SIZE);
        $total = $attachments->count();

        if ($total === 0) {
            $this->warn('No attachments found');
            return 0;
        }

        $include_external = $this->option('include-external');

        $this->info("Generating thumbnails for {$total} attachment(s), " . count($presets_to_generate) . " preset(s)");

        $generated_count = 0;
        $skipped_count = 0;
        $skipped_external_count = 0;
        $error_count = 0;

        foreach ($attachments as $attachment) {
            // Skip external (handler-backed) attachments that are not resident, unless asked to
            // materialize them. Avoids pulling remote bytes en masse during bulk cache warming.
            if (!$include_external && $attachment->handler_class !== null && $attachment->file_storage_id === null) {
                $skipped_external_count++;
                continue;
            }

            foreach ($presets_to_generate as $preset_name => $preset_config) {
                try {
                    $this->generate_thumbnail($attachment, $preset_name, $preset_config);
                    $generated_count++;
                } catch (\Exception $e) {
                    $this->error("Error generating {$preset_name} for {$attachment->key}: " . $e->getMessage());
                    $error_count++;
                }
            }
        }

        if ($skipped_external_count > 0) {
            $this->warn("Skipped {$skipped_external_count} external (handler-backed) attachment(s) not resident locally. Use --include-external to materialize and render them.");
        }

        $this->info("Complete: {$generated_count} generated, {$skipped_count} skipped (cached), {$error_count} errors");
        return $error_count > 0 ? 1 : 0;
    }

    /**
     * Generate thumbnail for attachment/preset combination
     *
     * @param File_Attachment_Model $attachment
     * @param string $preset_name
     * @param array $preset_config
     * @return void
     */
    protected function generate_thumbnail($attachment, $preset_name, $preset_config)
    {
        // Materialize external bytes on demand (only reached for resident or --include-external).
        $storage = $attachment->resolve_storage();
        if (!file_exists($storage->get_full_path())) {
            throw new \Exception('File not found on disk');
        }

        // Generate cache filename
        $cache_filename = File_Attachment_Controller::_get_cache_filename_preset(
            $preset_name,
            $storage->hash,
            $attachment->file_extension
        );
        $cache_path = File_Attachment_Controller::_get_cache_path('preset', $cache_filename);

        // Skip if already cached
        if (file_exists($cache_path)) {
            return;
        }

        $type = $preset_config['type'];
        $width = $preset_config['width'];
        $height = $preset_config['height'] ?? null;

        // Produce thumbnail bytes via the renderer registry (icon substitution handled inside).
        $thumbnail_data = File_Attachment_Controller::_render_thumbnail_data(
            $attachment,
            $storage->get_full_path(),
            $type,
            $width,
            $height
        );

        // Save to cache
        File_Attachment_Controller::_save_thumbnail_to_cache($cache_path, $thumbnail_data);
    }
}
