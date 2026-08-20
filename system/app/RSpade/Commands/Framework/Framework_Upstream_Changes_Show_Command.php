<?php

namespace App\RSpade\Commands\Framework;

use App\RSpade\Core\Framework\Upstream_Changes;
use Illuminate\Console\Command;

class Framework_Upstream_Changes_Show_Command extends Command
{
    protected $signature = 'rsx:framework:upstream_changes:show {name}';

    protected $description = 'View the full content and status of one upstream change';

    public function handle()
    {
        try {
            $filename = Upstream_Changes::resolve_filename($this->argument('name'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $manifest = Upstream_Changes::get_manifest();
        $record = $manifest[$filename] ?? null;

        if ($record !== null && ($record['status'] ?? null) === 'fulfilled') {
            $status_line = '<fg=green>fulfilled</> (updated ' . ($record['updated_at'] ?? '?') . ')';
        } elseif ($record !== null) {
            $status_line = '<fg=yellow>unfulfilled</> (updated ' . ($record['updated_at'] ?? '?') . ')';
        } else {
            $status_line = '<fg=yellow>unfulfilled</> (never reviewed)';
        }

        $rule = str_repeat('-', 78);

        $this->line('');
        $this->line('<fg=cyan>' . $filename . '</>');
        $this->line('Status: ' . $status_line);
        $this->line($rule);
        $this->line(Upstream_Changes::get_content($filename));
        $this->line($rule);
        $this->line('');

        return 0;
    }
}
