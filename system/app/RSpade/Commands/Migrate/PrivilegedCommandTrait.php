<?php

namespace App\RSpade\Commands\Migrate;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Trait for commands that need to run privileged operations (supervisorctl, file ops on /var/lib/mysql, etc.)
 *
 * Automatically prepends sudo when not running as root.
 */
trait PrivilegedCommandTrait
{
    /**
     * Check if we're running as root
     */
    protected function is_root(): bool
    {
        $whoami = trim(shell_exec('bash -c whoami') ?? '');
        return $whoami === 'root';
    }

    /**
     * Get sudo prefix if needed
     */
    protected function sudo_prefix(): string
    {
        return $this->is_root() ? '' : 'sudo ';
    }

    /**
     * Run a shell command string with sudo if needed
     */
    protected function shell_exec_privileged(string $command): ?string
    {
        $full_command = $this->sudo_prefix() . $command;
        return shell_exec('bash -c ' . escapeshellarg($full_command));
    }

    /**
     * Run a Process command array with sudo if needed.
     *
     * NO TIMEOUT. Symfony's Process defaults to 60s; this helper explicitly disables it.
     * Every caller here is a datadir-scale filesystem operation - `cp -rT` of /var/lib/mysql
     * to and from the snapshot, `rm -rf` of the snapshot, `chown -R` over the restored tree.
     * How long those take is a function of the database's SIZE, which is unbounded and not
     * something this code gets to have an opinion about.
     *
     * A timeout here does not make a slow copy fast - it converts a working operation into a
     * failed one, and on the RESTORE path (rollback_snapshot) it would abandon a half-copied
     * datadir, which is the exact corruption the -T flag and the sanity check exist to prevent.
     * If one of these commands genuinely hangs, that is a system fault to see and diagnose,
     * not something to paper over with a deadline.
     */
    protected function run_privileged_command(array $command, bool $throw_on_error = true): string
    {
        if (!$this->is_root()) {
            array_unshift($command, 'sudo');
        }

        $process = new Process($command);
        $process->setTimeout(null);
        $process->run();

        if ($throw_on_error && !$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }
}
