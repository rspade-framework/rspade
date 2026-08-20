<?php

namespace App\RSpade\Commands\Framework;

use App\RSpade\Core\Framework\Upstream_Changes;
use Illuminate\Console\Command;

class Framework_Upstream_Changes_Mark_Command extends Command
{
    protected $signature = 'rsx:framework:upstream_changes:mark {name} {--fulfilled} {--unfulfilled}';

    protected $description = 'Mark an upstream change as fulfilled or unfulfilled';

    public function handle()
    {
        $fulfilled = $this->option('fulfilled');
        $unfulfilled = $this->option('unfulfilled');

        if ($fulfilled === $unfulfilled) {
            // Both set or neither set.
            $this->error('Specify exactly one of --fulfilled or --unfulfilled.');
            $this->line('');
            $this->line('Usage:');
            $this->line('  php artisan rsx:framework:upstream_changes:mark <name> --fulfilled');
            $this->line('  php artisan rsx:framework:upstream_changes:mark <name> --unfulfilled');

            return 1;
        }

        try {
            $filename = Upstream_Changes::resolve_filename($this->argument('name'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        Upstream_Changes::mark($filename, (bool) $fulfilled);

        $state = $fulfilled ? 'fulfilled' : 'unfulfilled';
        $this->line("<fg=green>Marked '{$filename}' as {$state}.</>");

        return 0;
    }
}
