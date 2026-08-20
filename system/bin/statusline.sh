#!/usr/bin/env bash
#
# RSpade Claude Code status line:
#   model • effort • branch • hostname • dirty-count • ctx-tokens • weekly-quota% • Cur: • Rst:
#
# Claude Code pipes a JSON blob of session context to this script's stdin on each update;
# the first line of stdout is rendered (ANSI colors supported, no interactivity). Registered
# via .claude/settings.json (installed by system/bin/environment_updates/010_claude_statusline.sh).
#
# The hostname segment is the high-value RSX differentiator across parallel *.dev.hanson.xyz
# clones. MUST stay fast (<1s) - it runs on every session update. NO network, NO php artisan.
#
# Field paths (.model.display_name, .workspace.current_dir/.cwd) are the documented stable
# fields; branch + hostname are read from the filesystem, not the JSON, precisely to avoid
# depending on volatile payload fields. jq is used when present, with a sed fallback so the
# line still works (degraded) on a host without jq.
#
# OPTIONAL PAYLOAD SEGMENTS (omitted, separators included, when the payload lacks them -
# per the documented absence rules at code.claude.com/docs/en/statusline):
#   .effort.level                            - absent when the model has no effort setting
#   .context_window.total_input_tokens       - 0/null before the first API response; the sum
#                                              of fresh + cache-created + cache-read input,
#                                              i.e. what currently occupies the context
#   .context_window.used_percentage          - drives the ctx color only (may be null early)
#   .rate_limits.seven_day.used_percentage   - Claude.ai Pro/Max only, absent until the
#   .rate_limits.seven_day.resets_at           first API response of the session

set -uo pipefail
json="$(cat)"

# --- payload fields (graceful if jq missing) --------------------------------
effort=""
ctx_tokens=""
ctx_pct=""
week_pct=""
week_reset=""
if command -v jq >/dev/null 2>&1; then
  model="$(printf '%s' "$json" | jq -r '.model.display_name // "Claude"')"
  dir="$(printf '%s' "$json" | jq -r '.workspace.current_dir // .cwd // "."')"
  effort="$(printf '%s' "$json" | jq -r '.effort.level // empty')"
  ctx_tokens="$(printf '%s' "$json" | jq -r '.context_window.total_input_tokens // empty')"
  ctx_pct="$(printf '%s' "$json" | jq -r '.context_window.used_percentage // empty')"
  week_pct="$(printf '%s' "$json" | jq -r '.rate_limits.seven_day.used_percentage // empty')"
  week_reset="$(printf '%s' "$json" | jq -r '.rate_limits.seven_day.resets_at // empty')"
else
  model="$(printf '%s' "$json" | sed -n 's/.*"display_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -1)"
  model="${model:-Claude}"
  dir="$(printf '%s' "$json" | sed -n 's/.*"current_dir"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -1)"
  dir="${dir:-.}"
  # Degraded mode: the optional segments are simply omitted (nested extraction
  # via sed is not worth the fragility).
fi

cd "$dir" 2>/dev/null || true

# --- locate the nearest .env (used for the hostname AND the dirty-count scope) ---
host=""
envfile=""
d="$dir"
while [ -n "$d" ] && [ "$d" != "/" ]; do
  if [ -f "$d/.env" ]; then envfile="$d/.env"; break; fi
  d="$(dirname "$d")"
done

# --- git branch + dirty count -----------------------------------------------
# On a DOWNSTREAM app the vendored system/ tree carries machine-managed churn
# (override renames, use-rewrites) that the pre-commit hook strips from every app
# commit - so the count excludes system/ there and means "your app changes". In
# the framework monorepo (IS_FRAMEWORK_DEVELOPER=true) system/ IS the work and
# stays counted.
#
# --no-optional-locks IS LOAD-BEARING, NOT TIDINESS. A plain `git status` REFRESHES
# the index and takes .git/index.lock to write it back. This renderer runs on every
# status-line paint, and on a repo with a large index (node_modules is tracked here -
# ~18MB) that refresh is slow enough to collide with real work: a paint cut short mid
# -refresh orphans the lock, and the next `git add`/`git commit` dies with
# "Another git process seems to be running in this repository". Observed live
# 2026-08-11, twice in one minute, blocking a commit. --no-optional-locks makes
# status read-only - it reports from the index as-is and never writes it back.
branch="$(git --no-optional-locks rev-parse --abbrev-ref HEAD 2>/dev/null || echo '-')"
if [ -n "$envfile" ] && ! grep -qE '^IS_FRAMEWORK_DEVELOPER=true$' "$envfile" 2>/dev/null; then
  top="$(git --no-optional-locks rev-parse --show-toplevel 2>/dev/null)"
  changed="$(git --no-optional-locks -C "${top:-.}" status --porcelain -- ':(exclude)system' 2>/dev/null | wc -l | tr -d ' ')"
else
  changed="$(git --no-optional-locks status --porcelain 2>/dev/null | wc -l | tr -d ' ')"
fi

# --- hostname: the environment's domain name --------------------------------
# Authoritative source is the APP_URL host; fall back to the OS hostname. We must NOT boot
# Laravel here (too slow), so read the nearest .env directly by walking up from the cwd.
if [ -n "$envfile" ]; then
  app_url="$(grep -m1 '^APP_URL=' "$envfile" 2>/dev/null | cut -d= -f2- | tr -d '"'"'"'')"
  host="$(printf '%s' "$app_url" | sed -E 's#^[a-zA-Z]+://##; s#/.*$##; s#:[0-9]+$##')"
fi
# APP_URL is often the literal `https://$HOSTNAME` (resolved at boot, not in the file), so a
# $-containing value is unusable - fall back to the OS hostname, which is the resolved value.
case "$host" in ''|*'$'*) host="$(hostname 2>/dev/null || echo '?')" ;; esac

# --- colors -----------------------------------------------------------------
rst='\033[0m'
if [ "$changed" = "0" ]; then dc='\033[32m'; else dc='\033[33m'; fi

# Threshold color for a 0-100 percentage: green < 60, yellow < 85, red >= 85.
pct_color() {
  local whole="${1%%.*}"
  case "$whole" in (''|*[!0-9]*) printf '\033[32m'; return ;; esac
  if [ "$whole" -ge 85 ]; then printf '\033[31m'
  elif [ "$whole" -ge 60 ]; then printf '\033[33m'
  else printf '\033[32m'; fi
}

# --- assemble ---------------------------------------------------------------
line="$(printf '\033[36m%s\033[0m' "$model")"

# Effort: second value, right after the model.
if [ -n "$effort" ]; then
  line="$line$(printf ' • \033[34m%s\033[0m' "$effort")"
fi

line="$line$(printf ' • \033[35m%s\033[0m • \033[1m%s\033[0m • %b%s±%b' "$branch" "$host" "$dc" "$changed" "$rst")"

# Context tokens currently in the window (humanized), colored by used_percentage.
case "$ctx_tokens" in (''|*[!0-9]*) ctx_tokens="" ;; esac
if [ -n "$ctx_tokens" ] && [ "$ctx_tokens" -gt 0 ]; then
  if [ "$ctx_tokens" -ge 1000 ]; then ctx_h="$((ctx_tokens / 1000))k"; else ctx_h="$ctx_tokens"; fi
  line="$line$(printf ' • %b%s ctx%b' "$(pct_color "${ctx_pct:-0}")" "$ctx_h" "$rst")"
fi

# Weekly quota consumed (Claude.ai Pro/Max; absent otherwise).
if [ -n "$week_pct" ]; then
  week_whole="${week_pct%%.*}"
  line="$line$(printf ' • %b%s%% wk%b' "$(pct_color "$week_pct")" "$week_whole" "$rst")"
fi

# Current server time. Unlike every segment above it depends on NO payload field, so
# it always renders - which is the point: it is the reference the reset moment is read
# against, and without it "Tue 9:00 PM" leaves you doing timezone arithmetic in your
# head. %Z prints the server's own zone abbreviation (UTC here, CDT/CST on a US box),
# so both times are unambiguously in the same frame.
#
# Same FORMAT as Rst below, day-of-week included: the two only read as a before/after
# pair if they are shaped identically. Without the day, "Cur: 12:37 PM" against
# "Rst: Tue 9:00 PM" still leaves you working out which day you are on.
now_h="$(date '+%a %-I:%M %p %Z' 2>/dev/null || true)"
if [ -n "$now_h" ]; then
  line="$line$(printf ' • \033[2mCur: %s\033[0m' "$now_h")"
fi

# Weekly reset moment: day-of-week + local time (same availability as the quota).
if [ -n "$week_reset" ]; then
  case "$week_reset" in (*[!0-9]*) week_reset="" ;; esac
fi
if [ -n "$week_reset" ]; then
  reset_h="$(date -d "@$week_reset" '+%a %-I:%M %p %Z' 2>/dev/null || true)"
  if [ -n "$reset_h" ]; then
    line="$line$(printf ' • \033[2mRst: %s\033[0m' "$reset_h")"
  fi
fi

# printf %b would re-interpret backslashes in data; the escapes above are already expanded.
printf '%b' "$line"
