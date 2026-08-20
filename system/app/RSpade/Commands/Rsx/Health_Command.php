<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Health\Health_Check_Runner;
use App\RSpade\Core\Time\Rsx_Time;
use Illuminate\Console\Command;

/**
 * rsx:health - verify every dependency, service, and environment invariant RSpade relies
 * on is installed, reachable, and sane; report OK / WARN / FAIL with a remediation hint.
 *
 * Checks are DECLARED WHERE THEIR FEATURE LIVES via a bare `#[Health_Check('label')]`
 * attribute on a public static method, discovered through the manifest by
 * Health_Check_Runner - so the inventory can never drift from the features it covers.
 *
 * House style mirrors rsx:prod:verify: a plain Check/Status/Detail/Remediation table,
 * a [OK]/[FAIL] bracket summary, ASCII only (no emoji - a deliberate divergence from
 * rsx:debug's emoji). Exit 1 iff at least one FAIL row; WARN and INFO NEVER flip the
 * exit code, so a deploy pipeline / container healthcheck can gate on this command
 * while tolerating advisory warnings. `--json` emits a machine-readable payload only.
 */
class Health_Command extends Command
{
    protected $signature = 'rsx:health {--json : Machine-readable JSON output}';

    protected $description = 'Verify framework dependencies, services, and environment invariants';

    public function handle(): int
    {
        $rows = Health_Check_Runner::run();

        $fail_count = 0;
        $warn_count = 0;
        foreach ($rows as $row) {
            if ($row['status'] === 'FAIL') {
                $fail_count++;
            } elseif ($row['status'] === 'WARN') {
                $warn_count++;
            }
        }
        $ok = $fail_count === 0;

        if ($this->option('json')) {
            $this->line(json_encode([
                'generated_at' => Rsx_Time::now_iso(),
                'ok' => $ok,
                'checks' => array_map(static fn ($row) => [
                    'label' => $row['label'],
                    'status' => $row['status'],
                    'detail' => $row['detail'],
                    'remediation' => $row['remediation'],
                ], $rows),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $ok ? 0 : 1;
        }

        $table = array_map(static fn ($row) => [
            $row['label'],
            $row['status'],
            $row['detail'],
            $row['remediation'] ?? '',
        ], $rows);

        $this->table(['Check', 'Status', 'Detail', 'Remediation'], $table);
        $this->newLine();

        if (!$ok) {
            $this->error('[FAIL] ' . $fail_count . ' check(s) failing - see rows above');

            return 1;
        }

        $this->info('[OK] All health checks passed (' . count($rows) . ' checks, ' . $warn_count . ' warnings)');

        return 0;
    }
}
