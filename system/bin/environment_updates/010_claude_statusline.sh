#!/usr/bin/env bash
#
# 010 - Install the RSpade Claude Code status line + RSpade preference defaults into the
#       project's .claude/settings.json.
#
# AUGMENT-ONLY: merges a `statusLine` block into settings.json without touching anything else.
# Self-detecting + idempotent: silent when already installed by us; SKIPS (and reports) a
# developer's own custom status line rather than clobbering it; installs + reports only when
# absent. Uses php for the JSON merge (php is guaranteed in an RSpade env; jq is not).
# See system/bin/environment_updates/CLAUDE.md.
#
# ---------------------------------------------------------------------------------------
# PREFERENCE DEFAULTS - SET ONLY WHEN THE KEY IS ABSENT, NEVER OVERWRITTEN
# ---------------------------------------------------------------------------------------
# These are DEFAULTS, not policy. Contract rule 7 (never clobber developer state) governs:
# each key is written only if it is missing, so a developer who sets `effortLevel` - or who
# simply runs `/effort high`, which WRITES that key - keeps their value through every future
# pull. Delete a key to have the default restored on the next run; change it to keep yours.
#
#   attribution.commit = ""      Suppress the "Co-Authored-By: Claude" commit trailer.
#   attribution.pr     = ""      Suppress the PR-description attribution footer.
#                                (An empty string is how the documented `attribution` object
#                                disables each one. This SUPERSEDES `includeCoAuthoredBy`,
#                                which is deprecated - do not reintroduce it.)
#   effortLevel        = medium  Default reasoning effort. `/effort` overwrites it.
#   spinnerTipsEnabled = false   No tips in the working spinner.
#   cleanupPeriodDays  = 3650    Transcript retention. Claude Code deletes session files older
#                                than this AT STARTUP; the 30-day default silently destroys
#                                history you may want. ~10 years is "keep it".
#   env.CLAUDE_CODE_ENABLE_TELEMETRY = "0"
#                                Telemetry is OPT-IN (it activates only at exactly "1"), so
#                                this is belt-and-braces: it makes the off state EXPLICIT and
#                                survives an environment that exports the variable globally.
#                                There is no top-level "sendTelemetry" key - the env var is
#                                the only documented control.
#
# Verified against code.claude.com/docs/en/settings and .../monitoring-usage, 2026-08-11.
#
# BASH-PREFIXED COMMAND. The registered command runs the renderer as `bash "<path>"` rather
# than executing the path directly: downstream, `core.fileMode false` plus the pull's rsync
# make a repo script's exec bit unreliable, and a direct invocation then dies with
# "Permission denied". This script also HEALS an environment that still carries the earlier
# bare-path spelling of OUR OWN command (rewrite in place, one report line) - without that,
# the exact-string "is it ours?" test would misread our own prior spelling as a developer
# customization and skip forever.

set -uo pipefail

# CONTAINER GATE. Every environment_updates script runs ONLY inside the RSpade
# container. These scripts modify the environment around the project - git hooks,
# editor and agent settings, the on-disk storage layout - and that environment is
# the container's, not the host's. Run on a host they would install container
# assumptions into somebody's own machine, silently and with no way to know it
# happened. Absent marker, absent consent: exit 0 and say nothing (the contract
# is silent-when-not-applicable, and this is a normal state, not a failure).
[ -f /.rspade_container ] || exit 0

PROJECT_ROOT="${PROJECT_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
SYSTEM_DIR="${SYSTEM_DIR:-$PROJECT_ROOT/system}"

script_abs="$SYSTEM_DIR/bin/statusline.sh"
settings="$PROJECT_ROOT/.claude/settings.json"
# Claude Code expands $CLAUDE_PROJECT_DIR in the command field and runs the result through a
# shell, so the inner quotes protect a project path containing spaces.
cmd='bash "$CLAUDE_PROJECT_DIR/system/bin/statusline.sh"'
# The one earlier spelling this installer ever wrote. Matched EXACTLY - anything else in the
# statusLine slot is the developer's and is never touched.
cmd_prior='$CLAUDE_PROJECT_DIR/system/bin/statusline.sh'

# Keep the shipped renderer executable where the filesystem allows it. No longer load-bearing
# (the command is bash-prefixed), just tidy for anyone running it by hand.
[ -f "$script_abs" ] && chmod +x "$script_abs" 2>/dev/null || true

# Merge. Exit codes describe the STATUS LINE only: 0 = installed, 1 = statusLine already ours,
# 2 = custom statusLine -> skipped, 3 = error, 4 = upgraded our own prior spelling. The
# preference defaults are independent of all of them - any run may add them - so they are
# reported on stdout as a single `PREFS:<comma-list>` line the shell reads below. A run that
# only adds preferences still exits 1 (nothing to say about the status line) and is NOT silent.
out="$(RSX_SETTINGS="$settings" RSX_CMD="$cmd" RSX_CMD_PRIOR="$cmd_prior" php -r '
    $settings = getenv("RSX_SETTINGS");
    $cmd = getenv("RSX_CMD");
    $cmd_prior = getenv("RSX_CMD_PRIOR");
    $dir = dirname($settings);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { fwrite(STDERR, "cannot create ".$dir."\n"); exit(3); }

    $data = [];
    if (is_file($settings)) {
        $data = json_decode((string) file_get_contents($settings), true);
        if (!is_array($data)) { fwrite(STDERR, ".claude/settings.json is not valid JSON; leaving it untouched\n"); exit(3); }
    }

    // ---- Preference defaults: written ONLY where the key is absent. -------------------
    // Sub-keys are tested individually (attribution.commit separately from attribution.pr,
    // env.CLAUDE_CODE_ENABLE_TELEMETRY separately from the rest of env) so a developer who
    // set ONE of them keeps it while the others still get their default. array_key_exists,
    // not isset: an explicit null/false is a DECISION and must not read as absent.
    $added = [];

    $flat = ["effortLevel" => "medium", "spinnerTipsEnabled" => false, "cleanupPeriodDays" => 3650];
    foreach ($flat as $key => $value) {
        if (!array_key_exists($key, $data)) { $data[$key] = $value; $added[] = $key; }
    }

    if (!array_key_exists("attribution", $data) || !is_array($data["attribution"])) {
        if (!array_key_exists("attribution", $data)) { $data["attribution"] = []; }
        elseif (!is_array($data["attribution"])) { $data["attribution"] = []; }
    }
    foreach (["commit", "pr"] as $sub) {
        if (!array_key_exists($sub, $data["attribution"])) {
            $data["attribution"][$sub] = "";
            $added[] = "attribution." . $sub;
        }
    }

    if (!array_key_exists("env", $data) || !is_array($data["env"])) { $data["env"] = []; }
    if (!array_key_exists("CLAUDE_CODE_ENABLE_TELEMETRY", $data["env"])) {
        $data["env"]["CLAUDE_CODE_ENABLE_TELEMETRY"] = "0";
        $added[] = "env.CLAUDE_CODE_ENABLE_TELEMETRY";
    }

    // ---- Status line. -----------------------------------------------------------------
    $status_rc = 0;
    if (isset($data["statusLine"]["command"])) {
        $current = $data["statusLine"]["command"];
        if ($current === $cmd)            { $status_rc = 1; }   // already ours
        elseif ($current !== $cmd_prior)  { $status_rc = 2; }   // developer custom -> leave it
        else { $data["statusLine"]["command"] = $cmd; $status_rc = 4; }  // heal our prior spelling
    } else {
        $data["statusLine"] = ["type" => "command", "command" => $cmd, "padding" => 1];
    }

    // Nothing to change at all -> do not rewrite the file (preserves the developer formatting
    // and keeps mtime stable, so a healthy env is untouched on every pull).
    if (!$added && ($status_rc === 1 || $status_rc === 2)) { exit($status_rc); }

    if ($added) { echo "PREFS:", implode(",", $added), "\n"; }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if (file_put_contents($settings, $json) === false) { fwrite(STDERR, "failed to write ".$settings."\n"); exit(3); }
    exit($status_rc);
')"
rc=$?

[ "$rc" -eq 3 ] && exit 1   # surface the error to post-update (non-fatal there)

case "$rc" in
  0) echo "[env] Installed the RSpade Claude Code status line into .claude/settings.json (-> system/bin/statusline.sh)." ;;
  2) echo "[env] Claude Code status line: kept your custom statusLine in .claude/settings.json (not overwriting)." ;;
  4) echo "[env] Upgraded the status line command to bash-prefixed invocation." ;;
  *) : ;;        # 1 = already ours -> silent about the status line
esac

prefs="$(printf '%s\n' "$out" | sed -n 's/^PREFS://p' | head -1)"
if [ -n "$prefs" ]; then
  echo "[env] Claude Code settings: added RSpade defaults for ${prefs//,/, } (absent keys only; yours are never overwritten)."
fi
exit 0
