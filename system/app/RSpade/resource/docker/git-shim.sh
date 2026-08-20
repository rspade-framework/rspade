#!/bin/bash
#
# git, routed through the RSpade proxy.
#
# Installed as /usr/local/bin/git inside the RSpade container, which comes first
# on PATH - so `git` means `php artisan rsx:git` without anybody having to
# remember that. The real binary is still at /usr/bin/git.
#
# WHY THE PROXY EXISTS. system/ is a VENDORED tree: the framework owns it and
# rsx:framework:pull writes it. Committing it from an application repo captures
# whatever transient state that tree happens to be in - class-override sidecars,
# build artifacts, a half-applied update - and those commits then fight the next
# framework update. The proxy keeps system/ out of application commits and runs
# tree-rewriting operations inside a maintenance window so nothing is reading the
# tree while it changes. `git commit` doing the right thing by default is worth
# more than a rule everybody has to remember.
#
# THREE WAYS THIS GETS OUT OF THE WAY, because a wrapper that traps you is worse
# than no wrapper:
#
#   1. RE-ENTRANCY. The proxy is a shell script that calls `git` 87 times. With a
#      shim on PATH, every one of those would come back here - so the first thing
#      it does is export a marker, and seeing that marker means exec the real
#      binary immediately.
#
#   2. OUTSIDE THE PROJECT. Working on some other repository in this container is
#      none of the proxy's business.
#
#   3. NO WORKING PHP. The proxy runs through artisan. If php is missing or the
#      tree is broken enough that it will not start, git still has to work - that
#      is usually exactly when you need it.
#
set -u

REAL_GIT=/usr/bin/git
PROJECT_ROOT="${RSPADE_PROJECT_ROOT:-/var/www/html}"
ARTISAN="$PROJECT_ROOT/artisan"

# 1. Already inside the proxy: this is the proxy asking for real git.
if [ -n "${RSX_GIT_SHIM_ACTIVE:-}" ]; then
    exec "$REAL_GIT" "$@"
fi

# 2. Not working inside the project.
case "$PWD/" in
    "$PROJECT_ROOT"/*) ;;
    *) exec "$REAL_GIT" "$@" ;;
esac

# 3. Nothing to route through.
if [ ! -f "$ARTISAN" ] || ! command -v php >/dev/null 2>&1; then
    exec "$REAL_GIT" "$@"
fi

export RSX_GIT_SHIM_ACTIVE=1
exec php "$ARTISAN" rsx:git "$@"
