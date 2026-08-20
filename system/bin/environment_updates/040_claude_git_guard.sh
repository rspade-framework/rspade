#!/usr/bin/env bash
#
# 040 - Register the Claude Code git guard (system/bin/claude-git-guard.sh) as a
# PreToolUse hook in the project's .claude/settings.json.
#
# The guard blocks a bare `git` from the Bash tool and redirects to `php artisan rsx:git`,
# the transparent proxy that owns system/ safety and service quiescing (see the guard
# script's header for the full rationale). DOWNSTREAM ONLY - in the monorepo rsx:git is
# inert passthrough, so a redirect there would add a layer and buy nothing.
#
# SUPERSEDES the pre-pull guard this file used to install. That guard told the agent to
# run `rsx:clean` and then pull, which DEADLOCKS on a live box: the class-override churn
# reappears within ~1s of the clean, before the pull's index write can land. rsx:git fixes
# the problem the old guard could only announce, so this installer REMOVES both prior
# spellings of the pull-guard command while registering the new one.
#
# AUGMENT-ONLY: appends our hook entry to hooks.PreToolUse without touching existing hooks
# (foreign PreToolUse entries are preserved). Self-detecting + idempotent: silent when our
# entry is already registered and no stale entry remains. Uses php for the JSON merge (php
# is guaranteed; jq is not). See system/bin/environment_updates/CLAUDE.md.
#
# BASH-PREFIXED COMMAND. The registered command runs the guard as `bash "<path>"` rather
# than executing the path directly: downstream, `core.fileMode false` plus the pull's rsync
# make a repo script's exec bit unreliable, and a direct invocation then dies with
# "Permission denied" on every Bash tool call.

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
IS_FRAMEWORK_DEVELOPER="${IS_FRAMEWORK_DEVELOPER:-false}"

# Downstream only.
[ "$IS_FRAMEWORK_DEVELOPER" = true ] && exit 0

script_abs="$SYSTEM_DIR/bin/claude-git-guard.sh"
settings="$PROJECT_ROOT/.claude/settings.json"

# Claude Code expands $CLAUDE_PROJECT_DIR in the command field and runs the result through
# a shell, so the inner quotes protect a project path containing spaces.
cmd='bash "$CLAUDE_PROJECT_DIR/system/bin/claude-git-guard.sh"'

# Every spelling of the RETIRED pre-pull guard this installer ever wrote. Matched EXACTLY -
# any other command in a PreToolUse hook belongs to the developer and is never touched.
retired_1='bash "$CLAUDE_PROJECT_DIR/system/bin/claude-pull-guard.sh"'
retired_2='$CLAUDE_PROJECT_DIR/system/bin/claude-pull-guard.sh'

# Keep the shipped guard executable where the filesystem allows it. No longer load-bearing
# (the command is bash-prefixed), just tidy for anyone running it by hand.
[ -f "$script_abs" ] && chmod +x "$script_abs" 2>/dev/null || true

# Merge. Exit codes: 0 = installed, 1 = nothing to do (silent), 2 = installed AND removed
# the retired guard, 3 = error, 4 = removed the retired guard only.
RSX_SETTINGS="$settings" RSX_CMD="$cmd" RSX_OLD1="$retired_1" RSX_OLD2="$retired_2" php -r '
    $settings = getenv("RSX_SETTINGS");
    $cmd = getenv("RSX_CMD");
    $retired = [getenv("RSX_OLD1"), getenv("RSX_OLD2")];
    $dir = dirname($settings);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { fwrite(STDERR, "cannot create ".$dir."\n"); exit(3); }

    $data = [];
    if (is_file($settings)) {
        $data = json_decode((string) file_get_contents($settings), true);
        if (!is_array($data)) { fwrite(STDERR, ".claude/settings.json is not valid JSON; leaving it untouched\n"); exit(3); }
    }

    // Pass 1: is the current spelling already registered anywhere?
    $found = false;
    foreach (($data["hooks"]["PreToolUse"] ?? []) as $entry) {
        foreach (($entry["hooks"] ?? []) as $hook) {
            if (($hook["command"] ?? null) === $cmd) { $found = true; }
        }
    }

    // Pass 2: drop every retired pull-guard entry. Foreign hooks in the same entry are
    // untouched, and an entry is removed only if OUR hook was its last member.
    $removed = false;
    if (isset($data["hooks"]["PreToolUse"]) && is_array($data["hooks"]["PreToolUse"])) {
        $entries = [];
        foreach ($data["hooks"]["PreToolUse"] as $entry) {
            if (isset($entry["hooks"]) && is_array($entry["hooks"])) {
                $hooks = [];
                foreach ($entry["hooks"] as $hook) {
                    if (in_array($hook["command"] ?? null, $retired, true)) { $removed = true; continue; }
                    $hooks[] = $hook;
                }
                if ($hooks === [] && $entry["hooks"] !== []) { continue; }   // we emptied it -> drop
                $entry["hooks"] = array_values($hooks);
            }
            $entries[] = $entry;
        }
        $data["hooks"]["PreToolUse"] = array_values($entries);
    }

    if (!$found) {
        // Append our entry; every existing hook (ours or foreign) is preserved as-is.
        $data["hooks"]["PreToolUse"][] = [
            "matcher" => "Bash",
            "hooks"   => [["type" => "command", "command" => $cmd]],
        ];
    } elseif (!$removed) {
        exit(1);                                            // already ours, nothing rewritten
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if (file_put_contents($settings, $json) === false) { fwrite(STDERR, "failed to write ".$settings."\n"); exit(3); }

    if ($found && $removed) { exit(4); }
    exit($removed ? 2 : 0);
'
rc=$?

case "$rc" in
  0) echo "[env] Registered the Claude Code git guard in .claude/settings.json (bare git -> php artisan rsx:git)." ;;
  2) echo "[env] Replaced the retired pre-pull guard with the Claude Code git guard (bare git -> php artisan rsx:git)." ;;
  4) echo "[env] Removed the retired pre-pull guard from .claude/settings.json (superseded by rsx:git)." ;;
  3) exit 1 ;;   # surface the error to post-update (non-fatal there)
  *) : ;;        # 1 = already ours -> silent
esac
exit 0
