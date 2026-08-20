<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Dependencies\Dependency_Manager;
use Composer\Semver\Semver;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * RSX Composer Wrapper
 * ====================
 *
 * Framework-aware wrapper around composer for the APPLICATION dependency layer
 * (project-root composer.json -> /vendor). Never operates on the framework's own
 * system/composer.json.
 *
 * VERBS:
 *   require <packages...> [flags]  Install app-layer packages. Packages already on
 *                                  the framework's exposed surface are RECORDED as
 *                                  provided-by-framework (nothing installed);
 *                                  framework-internal packages are refused; the rest
 *                                  are installed for real (after regenerating the
 *                                  replace map).
 *   remove <packages...>           Un-record provided packages, or delegate to
 *                                  real composer remove.
 *   install / update [args]        Regenerate the replace map, then passthrough.
 *   provides                       List the exposed framework packages, versions,
 *                                  and recorded status.
 *   <anything else>                Verbatim passthrough to composer at the root
 *                                  (show, why, outdated, validate, ...).
 *
 * MECHANICS:
 * Trailing verbs/args/flags are read straight from $_SERVER['argv'] (validation is
 * disabled) so any composer command line passes through unchanged. Real composer runs
 * via Symfony Process with cwd = project root and streamed output.
 */
class Rsx_Composer_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:composer
        {verb? : Composer verb (require, remove, install, update, provides, or any composer command)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage application-layer composer packages (framework-aware wrapper)';

    /**
     * Real composer invocations can hit the network; give them room.
     */
    const PROCESS_TIMEOUT_SECONDS = 600;

    /**
     * Accept arbitrary trailing composer args without Symfony validating them.
     */
    protected function configure()
    {
        parent::configure();

        $this->ignoreValidationErrors();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Everything after the literal 'rsx:composer' token is the composer command line.
        $args = $this->_composer_args();

        if (empty($args)) {
            // Bare `rsx:composer` - passthrough to composer at the root.
            return $this->_run_composer([]);
        }

        $verb = $args[0];
        $rest = array_slice($args, 1);

        return match ($verb) {
            'require' => $this->_handle_require($rest),
            'remove'  => $this->_handle_remove($rest),
            'install', 'update' => $this->_handle_install_update($verb, $rest),
            'provides' => $this->_handle_provides(),
            default    => $this->_run_composer($args),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Verb handlers
    |--------------------------------------------------------------------------
    */

    /**
     * require: classify every package (exposed / framework-internal / new), fail loud
     * with NO side effects if any is refused, then record the exposed ones and install
     * the new ones.
     */
    private function _handle_require(array $rest): int
    {
        [$specs, $flags] = $this->_split_specs_and_flags($rest);

        if (empty($specs)) {
            // Nothing to classify - let composer handle it verbatim.
            return $this->_run_composer(array_merge(['require'], $rest));
        }

        $to_record = [];   // name => installed_version
        $to_install = [];  // full spec strings

        // -- Validation pass (no side effects) --
        foreach ($specs as $spec) {
            [$name, $constraint] = $this->_parse_spec($spec);

            if (Dependency_Manager::is_exposed_composer($name)) {
                $installed = Dependency_Manager::framework_composer_version($name);

                if ($installed === null) {
                    // Exposed on paper but absent from the framework installed set -
                    // a stale installed.json or a mis-configured exposure list. Refuse
                    // rather than record a null version.
                    $this->error(
                        "'{$name}' is on the exposed surface but was not found in the framework "
                        . 'installed set (system/vendor/composer/installed.json).'
                    );
                    $this->line('Report this to the framework maintainer. (No changes made.)');
                    return 1;
                }

                if ($constraint !== null && !$this->_constraint_satisfied($installed, $constraint)) {
                    $this->error(
                        "'{$name}' is provided by the framework at {$installed}, which does not satisfy "
                        . "your requested constraint '{$constraint}'."
                    );
                    $this->line(
                        "Require it without a version constraint to accept the framework's version, "
                        . 'or open a request to the framework maintainer.'
                    );
                    return 1;
                }

                $to_record[$name] = $installed;
                continue;
            }

            if (Dependency_Manager::is_framework_installed_composer($name)) {
                $this->error(
                    "'{$name}' is an internal framework dependency, not part of the supported surface."
                );
                $this->line(
                    'Open a request to the framework maintainer to expose it. (No changes made.)'
                );
                return 1;
            }

            $to_install[] = $spec;
        }

        // -- Side-effect pass (validation already passed) --
        foreach ($to_record as $name => $installed) {
            Dependency_Manager::record_provided_composer($name, $installed);
            $this->line(
                "<fg=green>[OK]</> '{$name}' provided by framework at {$installed} "
                . '- recorded (nothing installed).'
            );
        }

        if (empty($to_install)) {
            return 0;
        }

        // Real install: make sure the replace map reflects the current framework set
        // before composer solves against it.
        Dependency_Manager::regenerate_replace_map();

        return $this->_run_composer(array_merge(['require'], $to_install, $flags));
    }

    /**
     * remove: un-record provided packages; delegate the rest to real composer remove.
     */
    private function _handle_remove(array $rest): int
    {
        [$specs, $flags] = $this->_split_specs_and_flags($rest);

        if (empty($specs)) {
            return $this->_run_composer(array_merge(['remove'], $rest));
        }

        $to_remove = [];

        foreach ($specs as $spec) {
            [$name] = $this->_parse_spec($spec);

            if (Dependency_Manager::unrecord_provided_composer($name)) {
                $this->line(
                    "<fg=green>[OK]</> '{$name}' provided-by-framework recording removed."
                );
                continue;
            }

            $to_remove[] = $name;
        }

        if (empty($to_remove)) {
            return 0;
        }

        return $this->_run_composer(array_merge(['remove'], $to_remove, $flags));
    }

    /**
     * install / update: keep the replace map current, then passthrough.
     */
    private function _handle_install_update(string $verb, array $rest): int
    {
        Dependency_Manager::regenerate_replace_map();

        return $this->_run_composer(array_merge([$verb], $rest));
    }

    /**
     * provides: list the exposed framework composer packages with their versions and
     * whether each is currently recorded in this app's composer.json.
     */
    private function _handle_provides(): int
    {
        $exposed = config('rsx.dependencies.exposed_composer', []);
        sort($exposed);

        $recorded = Dependency_Manager::get_recorded_provided_composer();

        $rows = [];
        foreach ($exposed as $package) {
            $version = Dependency_Manager::framework_composer_version($package);

            $rows[] = [
                $package,
                $version ?? '(not installed)',
                array_key_exists($package, $recorded) ? 'yes' : 'no',
            ];
        }

        $this->line('');
        $this->line('Framework-provided composer packages (requestable via rsx:composer require):');
        $this->line('');
        $this->table(['Package', 'Framework Version', 'Recorded'], $rows);
        $this->line('');

        return 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The raw composer command line: everything after the 'rsx:composer' token in argv.
     */
    private function _composer_args(): array
    {
        $argv = $_SERVER['argv'] ?? [];

        $index = array_search('rsx:composer', $argv, true);

        if ($index === false) {
            return [];
        }

        return array_values(array_slice($argv, $index + 1));
    }

    /**
     * Split a verb's trailing args into package specs (positional) and flags (leading
     * dash). Order within each group is preserved.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function _split_specs_and_flags(array $args): array
    {
        $specs = [];
        $flags = [];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '-')) {
                $flags[] = $arg;
            } else {
                $specs[] = $arg;
            }
        }

        return [$specs, $flags];
    }

    /**
     * Split a composer package spec into [name, constraint|null].
     * "league/csv:^9.0" -> ['league/csv', '^9.0']; "league/csv" -> ['league/csv', null].
     *
     * @return array{0: string, 1: ?string}
     */
    private function _parse_spec(string $spec): array
    {
        $colon = strpos($spec, ':');

        if ($colon === false) {
            return [$spec, null];
        }

        return [substr($spec, 0, $colon), substr($spec, $colon + 1)];
    }

    /**
     * Whether the framework's installed $version satisfies the user's $constraint,
     * using composer's own semver library. An unparseable/exotic constraint is treated
     * as unsatisfiable (the caller then hard-fails with guidance).
     */
    private function _constraint_satisfied(?string $version, string $constraint): bool
    {
        if ($version === null) {
            return false;
        }

        try {
            return Semver::satisfies($version, $constraint);
        } catch (\UnexpectedValueException $e) {
            // Exotic/invalid constraint composer's parser cannot handle.
            return false;
        }
    }

    /**
     * Run real composer at the project root with streamed output. Returns composer's
     * exit code.
     */
    private function _run_composer(array $args): int
    {
        $process = new Process(
            array_merge(['composer'], $args),
            Dependency_Manager::project_root(),
            null,
            null,
            self::PROCESS_TIMEOUT_SECONDS
        );

        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        return $process->getExitCode() ?? 1;
    }
}
