#!/usr/bin/env bash
#
# claude-git-guard.sh - Claude Code PreToolUse hook (Bash matcher) for downstream apps.
#
# WHY: on a downstream app, plain git is unsafe. Three processes mutate the working tree
# during any git operation - the container's fixperms loop (which chmods files inside
# .git/), the JIT manifest rebuild re-applying the class-override pass to system/, and
# concurrent index writers - so a pull carrying framework-update commits races and aborts,
# and an agent's reflexive fixes (stash, or committing the churn into an app commit) are
# exactly the history-mixing the framework forbids.
#
# `php artisan rsx:git` owns all of that: system/ is never staged, tree-rewriting ops run
# inside a maintenance window with system/ reset to HEAD first, and system/ conflicts
# resolve to the incoming framework version automatically. It is a TRANSPARENT proxy -
# every subcommand, flag, exit code, stdin and TTY behavior is git's - so there is nothing
# to give up by routing through it.
#
# WHAT: reads the PreToolUse payload on stdin; if the Bash command invokes `git` directly,
# exits 2 (block) with the rsx:git equivalent on stderr. Everything else passes through.
#
# FAIL-OPEN BY DESIGN: this is a convenience guard, not a security boundary. Any
# parse/read failure - or a missing proxy script - exits 0 (allow). A guard bug must never
# break every Bash call.
#
# Registered in .claude/settings.json by environment_updates/040_claude_git_guard.sh
# (downstream only - in the monorepo rsx:git is inert passthrough, so the redirect would
# add a layer and buy nothing).

set -uo pipefail

# Project root: Claude Code exports CLAUDE_PROJECT_DIR to hooks; the script's own
# location (<root>/system/bin/) is the offline anchor.
root="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." 2>/dev/null && pwd)}"
[ -n "$root" ] && [ -d "$root/system" ] || exit 0

# Nothing to redirect TO -> never block.
[ -f "$root/system/bin/rsx-git.sh" ] || exit 0

# Runtime monorepo guard (belt - the installer never registers us there).
if [ -f "$root/.env" ] && grep -qE '^IS_FRAMEWORK_DEVELOPER=true[[:space:]]*$' "$root/.env" 2>/dev/null; then
    exit 0
fi

# Extract the Bash command from the hook payload. php is guaranteed in an RSpade
# environment; jq is not.
command_str="$(php -r '
    $payload = json_decode(stream_get_contents(STDIN), true);
    if (is_array($payload) && isset($payload["tool_input"]["command"]) && is_string($payload["tool_input"]["command"])) {
        echo $payload["tool_input"]["command"];
    }
' 2>/dev/null)" || exit 0
[ -n "$command_str" ] || exit 0

# `git` invoked as a command: at a statement start (line start, or after ; & | && || or an
# opening paren), allowing leading VAR=value assignments and an optional sudo. This does
# NOT match `php artisan rsx:git ...` (the token there is `rsx:git`, preceded by a colon),
# nor `git` appearing inside a quoted string mid-command.
printf '%s' "$command_str" \
    | grep -qE '(^|[;&|(]|&&|\|\|)[[:space:]]*([A-Za-z_][A-Za-z0-9_]*=[^[:space:]]*[[:space:]]+)*(sudo[[:space:]]+)?git([[:space:]]|$)' \
    || exit 0

cat >&2 <<'EOF'
BLOCKED: use `php artisan rsx:git ...` instead of bare `git` in this project.

rsx:git is a TRANSPARENT proxy - same subcommands, same flags, same exit codes, same
editor/pager/stdin behavior - that additionally owns the things plain git cannot see:

  * system/ (the vendored framework tree) is never shown or staged, so framework
    churn can never leak into an app commit;
  * pull / merge / rebase / reset --hard / checkout / stash pop run inside a
    maintenance window with system/ reset to HEAD first, so incoming framework-update
    commits apply cleanly instead of racing the manifest rebuild;
  * conflicts inside system/ resolve to the incoming framework version automatically.

Just prefix your command:

    php artisan rsx:git status
    php artisan rsx:git pull
    php artisan rsx:git add -A && php artisan rsx:git commit -m "..."

Overrides when you really mean it: --rsx-raw (show system/), --rsx-include-system
(stage a framework edit), --rsx-no-maint (skip the service cycle).
Details: php artisan rsx:man rsx_git
EOF
exit 2
