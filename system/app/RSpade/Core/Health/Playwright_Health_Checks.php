<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Health;

use Symfony\Component\Process\Process;

/**
 * Playwright_Health_Checks - boolean probes for the Playwright + Chromium stack that
 * rsx:debug (Route_Debug_Command) needs to render SPA pages.
 *
 * DELIBERATELY DIVERGES from Route_Debug_Command: that command auto-INSTALLS a missing
 * playwright package / chromium browser mid-run. rsx:health only PROBES and reports the
 * exact install command the operator would run - it never installs anything (a health
 * command must be a safe, read-only, non-mutating check).
 *
 * These live in the Health domain (not beside Route_Debug_Command) because
 * `#[Health_Check]` discovery is a manifest attribute scan and app/RSpade/Commands is
 * NOT in the manifest scan_directories - an attribute there would never be discovered.
 */
class Playwright_Health_Checks
{
    /**
     * node -> playwright package -> chromium browser, each a boolean probe. Short-circuits
     * to a single FAIL when node itself is absent (the other probes cannot run without it).
     *
     * @return array
     */
    #[Health_Check('Playwright / Chromium')]
    public static function playwright_stack(): array
    {
        // node is a prerequisite for every other probe here.
        $node = new Process(['node', '--version']);
        $node->setTimeout(10);
        $node->run();

        if (!$node->isSuccessful()) {
            return [
                'label' => 'Playwright / Chromium',
                'status' => 'FAIL',
                'detail' => 'node not found - required for Playwright and rsx:debug',
                'remediation' => 'install nodejs (apt-get install -y nodejs)',
            ];
        }

        $rows = [];

        // The playwright npm package must be require()-able from the framework root.
        $pkg = new Process(['node', '-e', "require('playwright')"], base_path());
        $pkg->setTimeout(15);
        $pkg->run();

        if (!$pkg->isSuccessful()) {
            $rows[] = [
                'label' => 'Playwright Package',
                'status' => 'FAIL',
                'detail' => "the 'playwright' npm package is not installed",
                'remediation' => 'npm install playwright',
            ];

            // Without the package the chromium probe cannot run - stop here.
            return $rows;
        }

        $rows[] = ['label' => 'Playwright Package', 'status' => 'OK', 'detail' => 'playwright is installed'];

        // Chromium: probe that the resolved executablePath actually exists on disk. Pure
        // filesystem check via node - never LAUNCHES a browser.
        $script = "const {chromium}=require('playwright');"
            . "const fs=require('fs');"
            . "const p=chromium.executablePath();"
            . "process.stdout.write(p||'');"
            . "process.exit(p && fs.existsSync(p) ? 0 : 1);";

        $chromium = new Process(['node', '-e', $script], base_path());
        $chromium->setTimeout(15);
        $chromium->run();

        if (!$chromium->isSuccessful()) {
            $path = trim($chromium->getOutput());
            $rows[] = [
                'label' => 'Chromium Browser',
                'status' => 'FAIL',
                'detail' => $path === ''
                    ? 'chromium browser binary not installed'
                    : 'chromium binary missing at ' . $path,
                'remediation' => 'npx playwright install chromium',
            ];

            return $rows;
        }

        $rows[] = [
            'label' => 'Chromium Browser',
            'status' => 'OK',
            'detail' => 'installed at ' . trim($chromium->getOutput()),
        ];

        return $rows;
    }
}
