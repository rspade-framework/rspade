<?php

namespace App\RSpade\Commands\Restricted;

use App\RSpade\Core\Prod\Rsx_Prod_Seal;
use Illuminate\Console\Command;

class Optimize_Cache_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'optimize:cache
                            {--authorized : Internal: forwarded by rsx:prod:build to mark an authorized prod-build context}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cache all cacheable Laravel components (config, routes, views, events)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Allowed ONLY in production, or from an authorized prod-build context
        // (rsx:prod:build forwards the --authorized invocation flag to this child
        // when it is itself authorized). This lets the sealed-build pipeline cache
        // Laravel components even when APP_ENV is not literally 'production' (e.g.
        // a --debug build on a local box), while still blocking ad-hoc dev use.
        if (!app()->environment('production') && !Rsx_Prod_Seal::is_authorized()) {
            throw new \RuntimeException(
                "The optimize:cache command should NEVER be called except in production\n" .
                "or from an authorized prod-build context (rsx:prod:enable/refresh).\n\n" .
                "In development mode, all caching should be disabled for proper development workflow.\n" .
                "Caching in development prevents you from seeing changes immediately and causes confusion.\n\n" .
                "Current environment: " . app()->environment() . "\n\n" .
                "If you need to test caching behavior, use a staging environment configured as 'production'."
            );
        }

        $this->info('Optimizing application caches for production...');
        $this->newLine();

        // NOTE: we deliberately do NOT call cache:clear here. RSX overrides
        // cache:clear to also run rsx:clean, which would wipe the freshly-built
        // rsx-build assets mid-pipeline. Each *:cache command below clears its own
        // compiled artifact (config:clear, route:clear, ...) before rewriting it,
        // so an explicit up-front clear is both redundant and destructive.

        // NOTE: 'config' and 'views' are intentionally omitted.
        //
        // - config: Laravel SKIPS loading .env entirely when a config cache exists,
        //   so every runtime env() call falls back to its default. RSX reads env()
        //   pervasively at runtime (RSX_MODE, APP_URL, the email dev flags,
        //   ...), so caching config silently breaks the app - most
        //   visibly Rsx::get_mode(), which would read 'development' on a sealed
        //   production box. RSX is env-at-runtime by design; config caching is a
        //   broader incompatibility to address separately, not here.
        // - views: RSX renders its view layer through jqhtml at runtime; Laravel's
        //   view:cache pre-compiles every Blade template and cannot statically
        //   resolve RSX's component tags.
        //
        // Route and event caching do NOT skip Dotenv loading, so they are safe and
        // deliver the real production routing/event startup win.
        $steps = [
            'routes' => 'Routes',
            'events' => 'Events',
        ];

        $failed = [];
        
        // For each cache type, we need to remove the override temporarily
        // and call the original Laravel command
        foreach ($steps as $cache_type => $label) {
            $this->info("Caching {$label}...");
            
            try {
                $exit_code = $this->cache_component($cache_type);
                
                if ($exit_code === 0) {
                    $this->line("  [OK] {$label} cached successfully");
                } else {
                    $failed[] = $label;
                    $this->error("  [ERROR] Failed to cache {$label}");
                }
            } catch (\Exception $e) {
                $failed[] = $label;
                $this->error("  [ERROR] Error caching {$label}: " . $e->getMessage());
            }

            $this->newLine();
        }

        if (empty($failed)) {
            $this->info('[OK] All caches optimized successfully');
            return 0;
        } else {
            $this->error('[WARNING] Some caches failed: ' . implode(', ', $failed));
            return 1;
        }
    }
    
    /**
     * Cache a specific component
     *
     * @param string $type
     * @return int
     */
    protected function cache_component($type)
    {
        // RSX overrides config:cache / route:cache / view:cache / event:cache with
        // restricted stubs, so calling them BY NAME (Artisan registry) would just
        // hit the stub. We instead construct and run the ORIGINAL Laravel command
        // object directly, which bypasses the name registry entirely. Their own
        // internal *:clear calls are not overridden, so they run cleanly.
        switch ($type) {
            case 'routes':
                $command = new \Illuminate\Foundation\Console\RouteCacheCommand(app('files'));
                break;

            case 'events':
                $command = new \Illuminate\Foundation\Console\EventCacheCommand();
                break;

            default:
                throw new \Exception("Unknown cache type: {$type}");
        }

        // Both the container AND the console Application must be set: these
        // original commands call their own *:clear counterparts via the Artisan
        // Application internally (config:clear etc. - none of which are overridden).
        $command->setLaravel(app());
        $command->setApplication($this->getApplication());

        return $command->run(new \Symfony\Component\Console\Input\ArrayInput([]), $this->output);
    }
}