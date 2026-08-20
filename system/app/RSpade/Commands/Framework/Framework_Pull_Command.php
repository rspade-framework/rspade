<?php

namespace App\RSpade\Commands\Framework;

use Illuminate\Console\Command;

/**
 * Framework Update Command - STUB ONLY
 *
 * This command should NEVER execute under normal circumstances.
 * The artisan script intercepts 'rsx:framework:pull' before Laravel loads
 * and directly executes bin/framework-pull-upstream.sh to avoid running
 * the framework update on potentially outdated framework code.
 *
 * This stub exists only for:
 * - Command registration (so 'php artisan list' shows the command)
 * - Help text display
 * - Edge cases where artisan interception fails
 */
class Framework_Pull_Command extends Command
{
    protected $signature = 'rsx:framework:pull
        {--yes : Consent non-interactively (required for the one-time submodule conversion)}
        {--force : Overwrite unauthorized owned-zone changes and take upstream on three-way conflicts; also proceeds when the tree cannot be verified}
        {--diff-system-changes : Print the offending local framework-zone diffs and exit; makes no changes}
        {--no-rebuild : Skip the post-update artisan rebuild (prints the manual steps)}
        {--no-commit : Skip the framework auto-commit of its system/ changes (leaves them staged for manual commit)}
        {--resync : Restore every framework-owned zone to the distribution tip and re-commit system/ as that release, regardless of what the release marker claims (recovery after a backwards merge; NOT --force - the tamper gate still protects locally modified files)}
        {--check-foreign-changes : Print any uncommitted changes under system/ and exit (1 if dirty, 0 if pristine); makes no changes, no network}
        {--no-service-control : Do not stop/start a supervised php-fpm around the sync}
        {--diff : Preview the changelog and diffstat the update would bring; makes no changes}
        {--upstream-url= : Override the distribution repository URL (for testing)}';

    protected $description = 'Pull RSpade framework updates: sync owned zones, three-way reconcile the rest, then commit the framework system/ changes (a pre-commit hook keeps system/ out of app commits)';

    public function handle()
    {
        // This should NEVER execute - artisan intercepts before Laravel loads
        $this->error('CRITICAL ERROR: Framework update command executed via Laravel.');
        $this->line('');
        $this->line('This indicates the artisan interception failed.');
        $this->line('The framework update script should run BEFORE Laravel loads.');
        $this->line('');
        $this->line('Please report this issue to framework developers.');

        return 1;
    }
}
