<?php

namespace App\RSpade\Commands\Framework;

use Illuminate\Console\Command;

class Framework_Post_Update_Command extends Command
{
    protected $signature = 'rsx:framework:post_update';

    protected $description = '[internal] Post-update orchestrator, invoked by the framework pull script';

    protected $hidden = true;

    public function handle()
    {
        // Orchestrator: the framework-pull bash script invokes this ONE command
        // after every update. Additional post-update steps belong here (each its
        // own sub-command call) so the bash script never has to change again.
        //
        // Env check runs FIRST: a deploy/clone may have materialized system/.env
        // into a real file, so restore the .env symlink invariant before any later
        // step (or the app) reads configuration.
        $this->call('rsx:framework:post_update_env_check');
        $this->call('rsx:framework:post_update_upstream_changes_check');
        $this->call('rsx:framework:post_update_dependency_check');

        return 0;
    }
}
