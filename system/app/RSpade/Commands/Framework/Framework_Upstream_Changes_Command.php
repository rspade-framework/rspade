<?php

namespace App\RSpade\Commands\Framework;

use App\RSpade\Core\Framework\Upstream_Changes;
use Illuminate\Console\Command;

class Framework_Upstream_Changes_Command extends Command
{
    protected $signature = 'rsx:framework:upstream_changes';

    protected $description = 'List pending (unfulfilled) upstream change mandates';

    public function handle()
    {
        $pending = Upstream_Changes::get_pending();
        $total = count(Upstream_Changes::list_all_files());

        $this->line('');

        if (empty($pending)) {
            $this->line('<fg=green>No pending upstream changes.</>');
            $this->line("All {$total} upstream changes reviewed.");
            $this->line('');

            return 0;
        }

        $pending_count = count($pending);
        $fulfilled_count = $total - $pending_count;

        $this->line('<fg=yellow>Pending upstream changes:</>');
        $this->line('');

        foreach ($pending as $filename) {
            $this->line('  - ' . $filename);
        }

        $this->line('');
        $this->line("{$pending_count} pending, {$fulfilled_count} fulfilled");
        $this->line('Run `php artisan rsx:framework:upstream_changes:show <name>` to review one.');
        $this->line('');

        return 0;
    }
}
