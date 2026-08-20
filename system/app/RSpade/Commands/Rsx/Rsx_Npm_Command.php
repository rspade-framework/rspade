<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Dependencies\Dependency_Manager;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * RSX npm Wrapper
 * ===============
 *
 * Framework-aware wrapper around npm for the APPLICATION dependency layer
 * (project-root package.json -> /node_modules). Never operates on the framework's
 * own system/package.json.
 *
 * esbuild compiles bundles with cwd = system/, and Node's standard upward walk
 * resolves system/node_modules FIRST, then /var/www/html/node_modules - so the
 * framework wins every overlap automatically and no NODE_PATH juggling is needed.
 *
 * VERBS:
 *   install <packages...> [flags]  Install app-layer packages (alias: i). Packages
 *                                  already on the framework's exposed surface are
 *                                  RECORDED as provided-by-framework (nothing
 *                                  installed); framework-internal packages are
 *                                  refused; the rest are installed for real. With NO
 *                                  package args, passes through (materialize from lock).
 *   uninstall <packages...>        Un-record provided packages, or delegate to real
 *                                  npm uninstall (aliases: remove, rm, un).
 *   update [args]                  Passthrough at the root.
 *   provides                       List the exposed framework npm packages, versions,
 *                                  and recorded status.
 *   <anything else>                Verbatim passthrough to npm at the root
 *                                  (ls, view, outdated, ...).
 *
 * MECHANICS:
 * Trailing verbs/args/flags are read straight from $_SERVER['argv'] (validation is
 * disabled) so any npm command line passes through unchanged. Real npm runs via
 * Symfony Process with cwd = project root and streamed output.
 */
class Rsx_Npm_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:npm
        {verb? : npm verb (install, uninstall, update, provides, or any npm command)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage application-layer npm packages (framework-aware wrapper)';

    /**
     * Real npm invocations can hit the network; give them room.
     */
    const PROCESS_TIMEOUT_SECONDS = 600;

    /**
     * Accept arbitrary trailing npm args without Symfony validating them.
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
        // Everything after the literal 'rsx:npm' token is the npm command line.
        $args = $this->_npm_args();

        if (empty($args)) {
            // Bare `rsx:npm` - passthrough to npm at the root.
            return $this->_run_npm([]);
        }

        $verb = $args[0];
        $rest = array_slice($args, 1);

        return match ($verb) {
            'install', 'i' => $this->_handle_install($rest),
            'uninstall', 'remove', 'rm', 'un' => $this->_handle_uninstall($rest),
            'update' => $this->_handle_update($rest),
            'provides' => $this->_handle_provides(),
            default  => $this->_run_npm($args),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Verb handlers
    |--------------------------------------------------------------------------
    */

    /**
     * install: classify every package (exposed / framework-internal / new), fail loud
     * with NO side effects if any is refused, then record the exposed ones and install
     * the new ones. With no package specs, passthrough (materialize from lock).
     */
    private function _handle_install(array $rest): int
    {
        [$specs, $flags] = $this->_split_specs_and_flags($rest);

        if (empty($specs)) {
            // Nothing to classify - `npm install` with no packages materializes from
            // the lock. Let npm handle it verbatim.
            return $this->_run_npm(array_merge(['install'], $rest));
        }

        $to_record = [];   // name => framework_version
        $to_install = [];  // full spec strings

        // -- Validation pass (no side effects) --
        foreach ($specs as $spec) {
            [$name, $version] = $this->_parse_spec($spec);

            if (Dependency_Manager::is_exposed_npm($name)) {
                $framework_version = Dependency_Manager::framework_npm_version($name);

                if ($framework_version === null) {
                    // Exposed on paper but absent from the framework package.json - a
                    // stale manifest or a mis-configured exposure list. Refuse rather
                    // than record a null version.
                    $this->error(
                        "'{$name}' is on the exposed surface but was not found in the framework "
                        . 'package.json (system/package.json).'
                    );
                    $this->line('Report this to the framework maintainer. (No changes made.)');
                    return 1;
                }

                if ($version !== null && !$this->_version_compatible($framework_version, $version)) {
                    $this->error(
                        "'{$name}' is provided by the framework at {$framework_version}, which does not "
                        . "satisfy your requested version '{$version}'."
                    );
                    $this->line(
                        "Install it without a version to accept the framework's version, "
                        . 'or open a request to the framework maintainer.'
                    );
                    return 1;
                }

                $to_record[$name] = $framework_version;
                continue;
            }

            if (Dependency_Manager::is_framework_installed_npm($name)) {
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
        foreach ($to_record as $name => $framework_version) {
            Dependency_Manager::record_provided_npm($name, $framework_version);
            $this->line(
                "<fg=green>[OK]</> '{$name}' provided by framework at {$framework_version} "
                . '- recorded (nothing installed).'
            );
        }

        if (empty($to_install)) {
            return 0;
        }

        return $this->_run_npm(array_merge(['install'], $to_install, $flags));
    }

    /**
     * uninstall: un-record provided packages; delegate the rest to real npm uninstall.
     */
    private function _handle_uninstall(array $rest): int
    {
        [$specs, $flags] = $this->_split_specs_and_flags($rest);

        if (empty($specs)) {
            return $this->_run_npm(array_merge(['uninstall'], $rest));
        }

        $to_remove = [];

        foreach ($specs as $spec) {
            [$name] = $this->_parse_spec($spec);

            if (Dependency_Manager::unrecord_provided_npm($name)) {
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

        return $this->_run_npm(array_merge(['uninstall'], $to_remove, $flags));
    }

    /**
     * update: passthrough at the root.
     */
    private function _handle_update(array $rest): int
    {
        return $this->_run_npm(array_merge(['update'], $rest));
    }

    /**
     * provides: list the exposed framework npm packages with their versions and
     * whether each is currently recorded in this app's package.json.
     */
    private function _handle_provides(): int
    {
        $exposed = config('rsx.dependencies.exposed_npm', []);
        sort($exposed);

        $recorded = Dependency_Manager::get_recorded_provided_npm();

        $rows = [];
        foreach ($exposed as $package) {
            $version = Dependency_Manager::framework_npm_version($package);

            $rows[] = [
                $package,
                $version ?? '(not installed)',
                array_key_exists($package, $recorded) ? 'yes' : 'no',
            ];
        }

        $this->line('');
        $this->line('Framework-provided npm packages (requestable via rsx:npm install):');
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
     * The raw npm command line: everything after the 'rsx:npm' token in argv.
     */
    private function _npm_args(): array
    {
        $argv = $_SERVER['argv'] ?? [];

        $index = array_search('rsx:npm', $argv, true);

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
     * Split an npm package spec into [name, version|null].
     *
     * The version separator is the LAST '@' whose index is greater than zero - which
     * correctly handles scoped packages whose leading '@' is the scope marker, not a
     * version separator:
     *   "js-cookie"            -> ['js-cookie', null]
     *   "js-cookie@3.0.5"      -> ['js-cookie', '3.0.5']
     *   "@scope/pkg"           -> ['@scope/pkg', null]
     *   "@scope/pkg@1.0.0"     -> ['@scope/pkg', '1.0.0']
     *
     * @return array{0: string, 1: ?string}
     */
    private function _parse_spec(string $spec): array
    {
        $at = strrpos($spec, '@');

        if ($at === false || $at === 0) {
            return [$spec, null];
        }

        return [substr($spec, 0, $at), substr($spec, $at + 1)];
    }

    /**
     * Whether the framework's declared $framework_range accepts the user's $requested
     * version. npm ranges are not fully parsed here - a simple prefix/exact comparison
     * after stripping leading range operators (^ ~ = v and spaces) is sufficient for a
     * sanity check: an exact match, or the framework version being a refinement of the
     * requested one (requested "3" or "3.3" against framework "3.3.1"), is compatible.
     * Anything else is treated as an exotic mismatch and hard-fails with guidance to
     * install unpinned.
     */
    private function _version_compatible(string $framework_range, string $requested): bool
    {
        $framework = $this->_normalize_version($framework_range);
        $wanted = $this->_normalize_version($requested);

        if ($framework === '' || $wanted === '') {
            return false;
        }

        if ($framework === $wanted) {
            return true;
        }

        // requested "3" / "3.3" is compatible with framework "3.3.1" - the framework
        // pin is a more specific version within the requested prefix.
        return str_starts_with($framework, $wanted . '.');
    }

    /**
     * Strip leading npm range operators and whitespace from a version string, keeping
     * only the version core up to the first space (so "^3.3.1" -> "3.3.1" and a
     * compound range like ">=1.87.0 <3.0.0" reduces to its first token "1.87.0").
     */
    private function _normalize_version(string $version): string
    {
        $trimmed = ltrim($version, " \t^~=vV<>");

        $space = strpos($trimmed, ' ');
        if ($space !== false) {
            $trimmed = substr($trimmed, 0, $space);
        }

        return $trimmed;
    }

    /**
     * Run real npm at the project root with streamed output. Returns npm's exit code.
     */
    private function _run_npm(array $args): int
    {
        $process = new Process(
            array_merge(['npm'], $args),
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
