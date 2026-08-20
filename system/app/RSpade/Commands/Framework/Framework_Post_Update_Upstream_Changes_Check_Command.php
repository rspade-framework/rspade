<?php

namespace App\RSpade\Commands\Framework;

use App\RSpade\Core\Framework\Upstream_Changes;
use Illuminate\Console\Command;

class Framework_Post_Update_Upstream_Changes_Check_Command extends Command
{
    protected $signature = 'rsx:framework:post_update_upstream_changes_check';

    protected $description = '[internal] Warn about pending upstream changes after a framework pull';

    protected $hidden = true;

    public function handle()
    {
        $pending = Upstream_Changes::get_pending();

        // Silent success: nothing pending, nothing to say.
        if (empty($pending)) {
            return 0;
        }

        $count = count($pending);

        $this->line('');
        $this->line('<fg=yellow>[WARNING] There are ' . $count . ' pending required upstream code changes to review that require immediate code refactoring.</>');
        $this->line('Run: php artisan rsx:framework:upstream_changes');
        $this->line('');

        return 0;
    }
}
